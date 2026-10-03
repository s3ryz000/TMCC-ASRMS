<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\AcademicStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #21: a student's program is students.program_id and their year level and
 * term come from their latest active enrollment. program_mappings duplicated
 * both, went stale after a program change, and is no longer written.
 */
class StudentProgramSourceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $staff;
    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1], 'C' => [1, 2]]);
        $this->staff = $this->makeUser('staff');
        $this->account = $this->makeUser('student', '2026-0001');
        $this->student = $this->makeStudent($this->program, [], $this->account);
    }

    private function profile(): array
    {
        Sanctum::actingAs($this->account, ['*']);

        return $this->getJson('/api/student/profile')->assertOk()->json();
    }

    private function enrollment(string $academicYear, string $semester, int $yearLevel, string $status, string $code = 'A'): Enrollment
    {
        return Enrollment::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects[$code]->id,
            'academic_year' => $academicYear, 'semester' => $semester, 'year_level' => $yearLevel, 'status' => $status,
        ]);
    }

    public function test_the_profile_shows_the_current_program_after_a_program_change(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $other = Program::create(['code' => 'BSX', 'name' => 'Other Program']);
        $this->patchJson("/api/staff/students/{$this->student->student_id}/program", ['new_program_id' => $other->id, 'reason' => 'Shift'])
            ->assertOk();

        $profile = $this->profile();

        $this->assertSame('BSX', $profile['program']['code']);
        $this->assertSame('Other Program', $profile['program_mapping']['program']['name']);
        $this->assertSame($other->id, $profile['program_mapping']['program_id']);
    }

    public function test_the_year_level_and_term_come_from_the_latest_active_enrollment(): void
    {
        $this->enrollment('2025-2026', '1', 1, AcademicStatus::PASSED);
        $this->enrollment('2026-2027', '1', 2, AcademicStatus::PASSED, 'B');
        $this->enrollment('2026-2027', '2', 2, AcademicStatus::ENROLLED, 'C');
        // Later, but cancelled or archived: not the student's current term.
        $this->enrollment('2027-2028', '1', 3, AcademicStatus::CANCELLED);
        $this->enrollment('2027-2028', '2', 3, AcademicStatus::ARCHIVED, 'B');

        $mapping = $this->profile()['program_mapping'];

        $this->assertSame('BSIT', $mapping['program']['code']);
        $this->assertSame('2026-2027', $mapping['academic_year']);
        $this->assertSame('2', (string) $mapping['semester']);
        $this->assertSame(2, (int) $mapping['year_level']);
    }

    public function test_a_student_with_no_enrollments_still_sees_their_program(): void
    {
        $profile = $this->profile();

        $this->assertSame('BSIT', $profile['program']['code']);
        $this->assertSame('BSIT', $profile['program_mapping']['program']['code']);
        $this->assertNull($profile['program_mapping']['year_level']);
    }

    public function test_no_enrollment_path_writes_program_mappings(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        // Guided next term (Edit Student).
        $this->enrollNextTerm($this->student, ['A'])->assertCreated();

        // Manual entry.
        $this->postJson("/api/staff/students/{$this->student->student_id}/enrollments", [
            'academic_year' => '2026-2027', 'semester' => '1st', 'year_level' => 1,
            'subject_ids' => $this->idsFor(['B']), 'status' => AcademicStatus::ENROLLED,
        ])->assertCreated();

        // New student with first-term subjects.
        $this->postJson('/api/staff/students', [
            'student_number' => '260100', 'first_name' => 'Ana', 'last_name' => 'Reyes',
            'date_of_birth' => '2006-02-02', 'email' => 'ana@tmcc.test', 'sex' => 'F',
            'enrollment_date' => '2026-06-01', 'program_id' => $this->program->id,
            'subject_ids' => $this->idsFor(['A', 'B']),
            'record_type' => 'Form 137', 'cabinet_no' => 'C1', 'shelf_no' => 'S1',
            'folder_code' => 'F1', 'document_status' => 'Complete',
        ])->assertCreated();

        $this->assertSame(4, Enrollment::count());
        $this->assertSame(0, DB::table('program_mappings')->count());
    }

    public function test_cancelling_the_last_subject_of_a_term_leaves_historical_program_mappings_alone(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A'])->assertCreated();
        $enrollment = Enrollment::sole();
        $row = [
            'student_id' => $this->student->student_id, 'program_id' => $this->program->id,
            'academic_year' => $enrollment->academic_year, 'semester' => $enrollment->semester,
            'year_level' => 1, 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now(),
        ];
        $id = DB::table('program_mappings')->insertGetId($row);

        $this->deleteJson("/api/staff/students/{$this->student->student_id}/enrollments/{$enrollment->id}", ['reason' => 'Dropped'])
            ->assertOk();

        $this->assertSame(AcademicStatus::CANCELLED, $enrollment->fresh()->status);
        $this->assertSame('enrolled', DB::table('program_mappings')->where('id', $id)->value('status'));
    }
}
