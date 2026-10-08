<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #19: grades.enrollment_id references enrollments.id (ON DELETE RESTRICT),
 * so a grade can't point at a missing enrollment and an enrollment with a
 * grade can't be hard-deleted.
 */
class GradeEnrollmentForeignKeyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1]]);
        $this->student = $this->makeStudent($this->program);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    /** column => "table.column ON DELETE", read the same way on SQLite and MySQL (#61). */
    private function foreignKeys(): array
    {
        return collect(Schema::getForeignKeys('grades'))
            ->mapWithKeys(fn ($fk) => [$fk['columns'][0] => "{$fk['foreign_table']}.{$fk['foreign_columns'][0]} " . strtoupper($fk['on_delete'])])
            ->sortKeys()
            ->all();
    }

    public function test_grades_keep_every_foreign_key_and_index(): void
    {
        $this->assertSame([
            'enrollment_id' => 'enrollments.id RESTRICT',
            'student_id'    => 'students.student_id CASCADE',
            'subject_id'    => 'subjects.id RESTRICT',
        ], $this->foreignKeys());

        $indexes = array_column(Schema::getIndexes('grades'), 'name');
        $this->assertContains('grades_student_id_subject_id_academic_year_semester_unique', $indexes);
        $enrollmentId = collect(Schema::getColumns('grades'))->firstWhere('name', 'enrollment_id');
        $this->assertNotNull($enrollmentId);
        $this->assertTrue($enrollmentId['nullable']);
    }

    public function test_a_grade_cannot_point_at_a_missing_enrollment(): void
    {
        $this->expectException(QueryException::class);

        Grade::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['A']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'enrollment_id' => 999999,
        ]);
    }

    public function test_an_enrollment_with_a_grade_cannot_be_hard_deleted(): void
    {
        $this->enrollNextTerm($this->student, ['A'])->assertCreated();
        $enrollment = Enrollment::sole();
        $grade = Grade::sole();
        $this->assertSame($enrollment->id, $grade->enrollment_id);

        try {
            $enrollment->forceDelete();
            $this->fail('Hard-deleting an enrollment with a grade should be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertModelExists($grade);
        $this->assertNotNull(Enrollment::withTrashed()->find($enrollment->id));
    }

    public function test_soft_deleting_an_enrollment_still_works(): void
    {
        $this->enrollNextTerm($this->student, ['A'])->assertCreated();

        Enrollment::sole()->delete();

        $this->assertSame(1, Enrollment::onlyTrashed()->count());
        $this->assertSame(1, Grade::count());
    }

    public function test_the_migration_refuses_when_a_grade_points_at_a_missing_enrollment(): void
    {
        $this->onlyOnSqlite('replays the migration that added this key to the existing SQLite data');

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_03_000004_add_foreign_key_to_grades_enrollment_id.php');
        $migration->down();

        $id = DB::table('grades')->insertGetId([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['B']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'enrollment_id' => 424242,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migration->up();
            $this->fail('An orphaned grade should stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("#{$id} (student {$this->student->student_id}, subject {$this->subjects['B']->id}, enrollment 424242)", $e->getMessage());
        }

        $this->assertArrayNotHasKey('enrollment_id', $this->foreignKeys());
        $this->assertSame(1, DB::table('grades')->where('id', $id)->count());

        DB::table('grades')->where('id', $id)->delete();
        $migration->up(); // leave the schema as the other tests expect
        $this->assertArrayHasKey('enrollment_id', $this->foreignKeys());
    }
}
