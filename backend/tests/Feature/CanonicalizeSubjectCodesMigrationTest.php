<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #16: the data migration merges duplicate subject rows without losing or
 * changing any student's record, and refuses to run when a merge would
 * collide.
 */
class CanonicalizeSubjectCodesMigrationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Migration $migration;
    private Program $bstm;
    private Program $bse;
    private Subject $pcBstm;
    private Subject $pcBse;
    private ?Student $student = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onlyOnSqlite('replays the one-time subject code repair of the existing SQLite data, which creates and drops tables');

        $this->migration = require database_path('migrations/2026_10_02_000001_canonicalize_subject_codes.php');
        // Back to the pre-#16 schema: UNIQUE(code, title), no change log.
        $this->migration->down();

        $this->bstm = Program::create(['code' => 'BSTM', 'name' => 'BS Tourism Management']);
        $this->bse = Program::create(['code' => 'BSE', 'name' => 'BS Entrepreneurship']);

        // Purposive Communication is GE 1 in BSTM but GE 5 in BSE.
        $this->pcBstm = Subject::create(['code' => 'GE 1', 'title' => 'Purposive Communication', 'units' => 3]);
        $this->pcBse = Subject::create(['code' => 'GE 5', 'title' => 'Purposive Communication', 'units' => 3]);
        $utsBse = Subject::create(['code' => 'GE 1', 'title' => 'Understanding the Self', 'units' => 3]);
        $later = Subject::create(['code' => 'ENT 9', 'title' => 'Business Writing', 'units' => 3]);

        Curriculum::create(['program_id' => $this->bstm->id, 'subject_id' => $this->pcBstm->id, 'year_level' => 1, 'semester' => 1]);
        Curriculum::create(['program_id' => $this->bse->id, 'subject_id' => $this->pcBse->id, 'year_level' => 1, 'semester' => 2]);
        Curriculum::create(['program_id' => $this->bse->id, 'subject_id' => $utsBse->id, 'year_level' => 1, 'semester' => 1]);
        Curriculum::create(['program_id' => $this->bse->id, 'subject_id' => $later->id, 'year_level' => 2, 'semester' => 1])
            ->prerequisites()->attach($this->pcBse->id);
    }

    private function recordFor(Subject $subject, string $ay = '2026-2027', string $sem = '2'): int
    {
        $student = $this->student ??= $this->makeStudent($this->bse);

        $enrollment = Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $subject->id,
            'academic_year' => $ay, 'semester' => $sem, 'status' => 'completed', 'year_level' => 1,
        ]);
        Grade::create([
            'student_id' => $student->student_id, 'subject_id' => $subject->id, 'enrollment_id' => $enrollment->id,
            'academic_year' => $ay, 'semester' => $sem, 'grade_value' => 1.75, 'status' => 'Passed',
        ]);
        EnrollmentAuditLog::create([
            'student_id' => $student->student_id, 'enrollment_id' => $enrollment->id, 'subject_id' => $subject->id,
            'academic_year' => $ay, 'semester' => $sem, 'action' => 'created', 'new_status' => 'completed',
        ]);

        return $student->student_id;
    }

    public function test_duplicates_merge_into_the_lowest_id_and_every_reference_follows(): void
    {
        $studentId = $this->recordFor($this->pcBse);

        $this->migration->up();

        $survivor = Subject::where('code', 'GEC-PC')->sole();
        $this->assertSame($this->pcBstm->id, $survivor->id);
        $this->assertSame('Purposive Communication', $survivor->title);
        $this->assertModelMissing($this->pcBse);

        // Both programs now list the one subject, in their original terms.
        $this->assertSame(2, Curriculum::where('subject_id', $survivor->id)->count());
        $this->assertDatabaseHas('curriculum', ['program_id' => $this->bse->id, 'subject_id' => $survivor->id, 'semester' => '2']);

        // The BSE student's record moved with it, unchanged.
        $this->assertDatabaseHas('enrollments', ['student_id' => $studentId, 'subject_id' => $survivor->id, 'academic_year' => '2026-2027']);
        $this->assertDatabaseHas('grades', ['student_id' => $studentId, 'subject_id' => $survivor->id, 'status' => 'Passed']);
        $this->assertDatabaseHas('enrollment_audit_logs', ['student_id' => $studentId, 'subject_id' => $survivor->id]);
        $this->assertDatabaseHas('curriculum_prerequisites', ['prerequisite_subject_id' => $survivor->id]);
        $this->assertSame(1, Grade::count());

        // Single-row renames and the change log.
        $this->assertDatabaseHas('subjects', ['code' => 'GEC-UTS', 'title' => 'Understanding the Self']);
        $this->assertDatabaseHas('subject_code_changes', ['subject_id' => $survivor->id, 'old_code' => 'GE 1', 'new_code' => 'GEC-PC']);

        $this->assertContains('subjects_code_unique', array_column(Schema::getIndexes('subjects'), 'name'));
    }

    public function test_down_restores_the_original_codes_of_surviving_rows(): void
    {
        $this->migration->up();
        $this->migration->down();

        $this->assertSame('GE 1', $this->pcBstm->fresh()->code);
        $this->assertDatabaseHas('subjects', ['code' => 'GE 1', 'title' => 'Understanding the Self']);
        $this->assertContains('subjects_code_title_unique', array_column(Schema::getIndexes('subjects'), 'name'));

        $this->migration->up(); // leave the schema as RefreshDatabase expects
    }

    public function test_a_collision_stops_the_merge_and_changes_nothing(): void
    {
        // The same student graded in both duplicates in the same term.
        $this->recordFor($this->pcBstm);
        $this->recordFor($this->pcBse);
        $before = DB::table('subjects')->orderBy('id')->get()->toArray();

        try {
            $this->migration->up();
            $this->fail('The migration should refuse to merge.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot merge into GEC-PC', $e->getMessage());
        }

        $this->assertEquals($before, DB::table('subjects')->orderBy('id')->get()->toArray());
        $this->assertSame(2, Grade::count());
        $this->assertFalse(Schema::hasTable('subject_code_changes'));

        // Resolve the clash so RefreshDatabase's schema is restored for the next test.
        Grade::where('subject_id', $this->pcBse->id)->delete();
        Enrollment::where('subject_id', $this->pcBse->id)->forceDelete();
        $this->migration->up();
    }
}
