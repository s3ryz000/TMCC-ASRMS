<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Support\AcademicStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #18: one status vocabulary (App\Support\AcademicStatus) for enrollments and
 * grades. SQLite compares strings case-sensitively, so a lowercase spelling in
 * a query matched nothing.
 */
class StatusVocabularyTest extends TestCase
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

    public function test_a_program_change_archives_the_old_programs_enrolled_subjects(): void
    {
        // Enrolled the way the system stores it (EnrollmentService writes "Enrolled").
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $other = Program::create(['code' => 'BSX', 'name' => 'Other Program']);

        $this->patchJson("/api/staff/students/{$this->student->student_id}/program", ['new_program_id' => $other->id, 'reason' => 'Shift'])
            ->assertOk()
            ->assertJsonPath('archived_count', 2);

        $this->assertSame([AcademicStatus::ARCHIVED], Enrollment::where('student_id', $this->student->student_id)->distinct()->pluck('status')->all());
    }

    public function test_storing_an_enrollment_accepts_the_canonical_statuses_in_any_case(): void
    {
        $url = "/api/staff/students/{$this->student->student_id}/enrollments";
        $base = ['academic_year' => '2026-2027', 'semester' => '1st', 'year_level' => 1];

        $this->postJson($url, $base + ['subject_ids' => $this->idsFor(['A']), 'status' => 'enrolled'])->assertCreated();
        $this->assertSame(AcademicStatus::ENROLLED, Enrollment::where('subject_id', $this->subjects['A']->id)->value('status'));

        $this->postJson($url, $base + ['subject_ids' => $this->idsFor(['B']), 'status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_editing_an_enrollment_the_service_created_stores_canonical_statuses(): void
    {
        $this->enrollNextTerm($this->student, ['A'])->assertCreated();
        $enrollment = Enrollment::sole();
        $url = "/api/staff/students/{$this->student->student_id}/enrollments/{$enrollment->id}";

        $this->putJson($url, ['status' => 'cancelled'])->assertOk();
        $this->assertSame(AcademicStatus::CANCELLED, $enrollment->fresh()->status);

        $this->putJson($url, ['status' => 'enrolled'])->assertOk();
        $this->assertSame(AcademicStatus::ENROLLED, $enrollment->fresh()->status);
    }

    public function test_canonical_matches_any_case_and_refuses_unknown_values(): void
    {
        $this->assertSame('INC', AcademicStatus::canonical('inc'));
        $this->assertSame('Archived', AcademicStatus::canonical(' ARCHIVED '));
        $this->assertSame('DRP', AcademicStatus::canonical('dropped'));
        $this->assertNull(AcademicStatus::canonical('completed'));
        $this->assertNull(AcademicStatus::canonical('Archived', AcademicStatus::ENROLLMENT_EDITABLE));
    }

    // ------------------------------------------------------------ migration

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_03_000003_normalise_status_casing.php');
    }

    private function rawEnrollment(string $code, ?string $status): int
    {
        return DB::table('enrollments')->insertGetId([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects[$code]->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'year_level' => 1, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_migration_normalises_mixed_case_statuses(): void
    {
        $a = $this->rawEnrollment('A', 'archived');
        $b = $this->rawEnrollment('B', 'enrolled');
        DB::table('grades')->insert([
            ['student_id' => $this->student->student_id, 'subject_id' => $this->subjects['A']->id, 'enrollment_id' => $a,
                'academic_year' => '2026-2027', 'semester' => '1', 'status' => 'passed', 'created_at' => now(), 'updated_at' => now()],
            ['student_id' => $this->student->student_id, 'subject_id' => $this->subjects['B']->id, 'enrollment_id' => $b,
                'academic_year' => '2026-2027', 'semester' => '1', 'status' => 'inc', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->migration()->up();

        $this->assertSame('Archived', DB::table('enrollments')->where('id', $a)->value('status'));
        $this->assertSame('Enrolled', DB::table('enrollments')->where('id', $b)->value('status'));
        $this->assertEqualsCanonicalizing(['Passed', 'INC'], DB::table('grades')->pluck('status')->all());
        $this->assertSame(2, DB::table('enrollments')->count());
    }

    public function test_the_migration_refuses_an_unknown_status_and_changes_nothing(): void
    {
        $a = $this->rawEnrollment('A', 'archived');
        $this->rawEnrollment('B', 'completed');

        try {
            $this->migration()->up();
            $this->fail('An unknown status should stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('enrollments.status "completed"', $e->getMessage());
        }

        $this->assertSame('archived', DB::table('enrollments')->where('id', $a)->value('status'));
    }
}
