<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers every path that can create or move an enrollment.
 */
class EnrollmentValidationTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;
    private Student $student;
    private User $staff;

    /** @var array<string, Subject> */
    private array $subjects = [];

    /** @var array<string, Curriculum> */
    private array $curricula = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['staff', 'admin', 'student'] as $role) {
            Role::findOrCreate($role, 'api');
        }

        $this->staff = User::create([
            'name' => 'Registrar Staff',
            'email' => 'staff@tmcc.test',
            'username' => 'staff01',
            'role' => 'staff',
            'password' => bcrypt('secret'),
        ]);
        $this->staff->assignRole('staff');

        $this->program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology']);

        // Y1S1: PROG1, GENED1 (no prerequisites)
        // Y1S2: PROG2 (requires PROG1)
        // Y2S1: PROG3 (requires PROG2), ELEC1 (PROG1 OR PROG2), BROKEN (unresolved)
        $defs = [
            'PROG1'  => [1, 1],
            'GENED1' => [1, 1],
            'PROG2'  => [1, 2],
            'PROG3'  => [2, 1],
            'ELEC1'  => [2, 1],
            'BROKEN' => [2, 1],
        ];

        foreach ($defs as $code => $spec) {
            $this->subjects[$code] = Subject::create([
                'code'  => $code,
                'title' => 'Subject ' . $code,
                'units' => 3,
            ]);

            $this->curricula[$code] = Curriculum::create([
                'program_id' => $this->program->id,
                'subject_id' => $this->subjects[$code]->id,
                'year_level' => $spec[0],
                'semester'   => $spec[1],
            ]);
        }

        $this->curricula['PROG2']->prerequisites()->sync([$this->subjects['PROG1']->id]);
        $this->curricula['PROG3']->prerequisites()->sync([$this->subjects['PROG2']->id]);

        $this->curricula['ELEC1']->prerequisites()->sync([
            $this->subjects['PROG1']->id,
            $this->subjects['PROG2']->id,
        ]);
        $this->curricula['ELEC1']->update(['prerequisite_logic' => 'OR']);

        $this->curricula['BROKEN']->update(['unresolved_prerequisites' => ['TPC 3']]);

        $this->student = Student::create([
            'program_id'      => $this->program->id,
            'student_number'  => '2026-0001',
            'first_name'      => 'Juan',
            'last_name'       => 'Dela Cruz',
            'date_of_birth'   => '2005-01-01',
            'email'           => 'juan@tmcc.test',
            'sex'             => 'M',
            'enrollment_date' => '2026-06-01',
        ]);

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function pass(string $code, string $ay = '2025-2026', int $sem = 1): void
    {
        Grade::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects[$code]->id,
            'academic_year' => $ay,
            'semester'      => $sem,
            'grade_value'   => 2.00,
            'status'        => 'Passed',
        ]);
    }

    private function addEnrollment(array $payload)
    {
        return $this->postJson(
            '/api/staff/students/' . $this->student->student_id . '/enrollments',
            $payload
        );
    }

    // ---------------------------- Path A ----------------------------

    public function test_path_a_no_longer_returns_500_and_actually_enrolls(): void
    {
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('enrolled_count', 1);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $this->student->student_id,
            'subject_id' => $this->subjects['PROG1']->id,
            'status'     => 'Enrolled',
            'year_level' => 1,
        ]);
    }

    public function test_path_a_writes_grade_and_audit_rows_too(): void
    {
        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id],
        ])->assertStatus(201);

        // Gap #1: both of these were missing on this path entirely.
        $this->assertDatabaseHas('grades', [
            'student_id' => $this->student->student_id,
            'subject_id' => $this->subjects['PROG1']->id,
            'status'     => 'Enrolled',
        ]);

        $this->assertDatabaseHas('enrollment_audit_logs', [
            'student_id' => $this->student->student_id,
            'subject_id' => $this->subjects['PROG1']->id,
            'action'     => 'enrollment_created',
        ]);
    }

    public function test_path_a_blocks_unmet_prerequisite(): void
    {
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '2nd',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG2']->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG1', json_encode($response->json()));
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_path_a_allows_subject_once_prerequisite_is_passed(): void
    {
        $this->pass('PROG1');

        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '2nd',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG2']->id],
        ])->assertStatus(201);
    }

    public function test_path_a_has_no_same_batch_prerequisite_bypass(): void
    {
        // PROG1 and PROG2 submitted together: PROG2 must still be refused.
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id, $this->subjects['PROG2']->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_path_a_rejects_subject_outside_the_selected_term(): void
    {
        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG3']->id],
        ])->assertStatus(422);
    }

    public function test_path_a_skips_duplicates_instead_of_failing_the_batch(): void
    {
        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id],
        ])->assertStatus(201);

        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id, $this->subjects['GENED1']->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('enrolled_count', 1);
        $response->assertJsonPath('skipped_duplicates', 1);
    }

    public function test_path_a_enrolls_whole_term_when_no_subjects_given(): void
    {
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('enrolled_count', 2); // PROG1 + GENED1
    }

    public function test_path_a_rejects_unrecognised_semester_instead_of_defaulting(): void
    {
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => 'summer',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_or_prerequisite_group_is_satisfied_by_one_member(): void
    {
        $this->pass('PROG1'); // ELEC1 requires PROG1 OR PROG2

        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 2,
            'subject_ids'   => [$this->subjects['ELEC1']->id],
        ])->assertStatus(201);
    }

    public function test_unresolved_prerequisite_blocks_enrollment(): void
    {
        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 2,
            'subject_ids'   => [$this->subjects['BROKEN']->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('unresolved', strtolower(json_encode($response->json())));
    }

    public function test_credited_status_counts_as_passed(): void
    {
        // The old Path A copy accepted only 1.00-3.00 or the exact string
        // 'PASSED', so a Credited subject with no numeric grade was invisible.
        Grade::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects['PROG1']->id,
            'academic_year' => '2025-2026',
            'semester'      => 1,
            'grade_value'   => null,
            'status'        => 'Credited',
        ]);

        $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '2nd',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG2']->id],
        ])->assertStatus(201);
    }

    public function test_already_passed_subject_is_not_re_enrolled(): void
    {
        $this->pass('PROG1');

        $response = $this->addEnrollment([
            'academic_year' => '2026-2027',
            'semester'      => '1st',
            'year_level'    => 1,
            'subject_ids'   => [$this->subjects['PROG1']->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('enrollments', 0);
    }

    // ---------------------------- Path C ----------------------------

    private function newStudentPayload(array $subjectIds): array
    {
        return [
            'student_number'  => '2026-9999',
            'first_name'      => 'Maria',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2005-05-05',
            'email'           => 'maria@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
            'program_id'      => $this->program->id,
            'subject_ids'     => $subjectIds,
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ];
    }

    public function test_path_c_accepts_first_year_first_semester_subjects(): void
    {
        $response = $this->postJson('/api/staff/students', $this->newStudentPayload([
            $this->subjects['PROG1']->id,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('students', ['student_number' => '2026-9999']);
    }

    public function test_path_c_rejects_subject_from_a_later_term(): void
    {
        $response = $this->postJson('/api/staff/students', $this->newStudentPayload([
            $this->subjects['PROG3']->id,
        ]));

        $response->assertStatus(422);

        // The whole creation must roll back.
        $this->assertDatabaseMissing('students', ['student_number' => '2026-9999']);
        $this->assertDatabaseMissing('users', ['username' => '2026-9999']);
    }

    public function test_path_c_rejects_subject_from_another_program(): void
    {
        $other = Program::create(['code' => 'BSHM', 'name' => 'BS Hospitality Management']);
        $foreign = Subject::create(['code' => 'HM101', 'title' => 'Foreign Subject', 'units' => 3]);

        Curriculum::create([
            'program_id' => $other->id,
            'subject_id' => $foreign->id,
            'year_level' => 1,
            'semester'   => 1,
        ]);

        $response = $this->postJson('/api/staff/students', $this->newStudentPayload([$foreign->id]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('students', ['student_number' => '2026-9999']);
    }

    /** #75: what the restored New Student picker sends becomes the first term. */
    public function test_path_c_enrolls_every_selected_first_term_subject(): void
    {
        $this->postJson('/api/staff/students', $this->newStudentPayload([
            $this->subjects['PROG1']->id,
            $this->subjects['GENED1']->id,
        ]))->assertCreated();

        $student = Student::where('student_number', '2026-9999')->firstOrFail();
        $enrollments = Enrollment::where('student_id', $student->student_id)->get();

        $this->assertEqualsCanonicalizing(
            [$this->subjects['PROG1']->id, $this->subjects['GENED1']->id],
            $enrollments->pluck('subject_id')->all()
        );
        $this->assertSame([1], $enrollments->pluck('year_level')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame([1], $enrollments->pluck('semester')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(2, Grade::where('student_id', $student->student_id)->count());
    }

    public function test_path_c_first_term_follows_the_enrollment_date(): void
    {
        // The "current term" setting disagrees with the enrollment date, as it
        // does for any record encoded after the student's first year.
        \App\Models\SystemSetting::setValue('academic_year', '2030-2031');

        $this->postJson('/api/staff/students', $this->newStudentPayload([
            $this->subjects['PROG1']->id,
        ]))->assertCreated();

        $student = Student::where('student_number', '2026-9999')->firstOrFail();

        $this->assertDatabaseHas('enrollments', [
            'student_id'    => $student->student_id,
            'academic_year' => '2026-2027',
            'year_level'    => 1,
            'semester'      => 1,
        ]);
    }

    public function test_path_c_writes_grade_rows(): void
    {
        $this->postJson('/api/staff/students', $this->newStudentPayload([
            $this->subjects['PROG1']->id,
        ]))->assertStatus(201);

        $created = Student::where('student_number', '2026-9999')->first();

        $this->assertDatabaseHas('grades', [
            'student_id' => $created->student_id,
            'subject_id' => $this->subjects['PROG1']->id,
            'status'     => 'Enrolled',
        ]);
    }

    // ---------------------------- Path D ----------------------------

    public function test_path_d_blocks_move_into_term_with_unmet_prerequisites(): void
    {
        $enrollment = Enrollment::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects['PROG2']->id,
            'academic_year' => '2026-2027',
            'semester'      => 1,
            'year_level'    => 1,
            'status'        => 'Enrolled',
        ]);

        $response = $this->putJson(
            '/api/staff/students/' . $this->student->student_id . '/enrollments/' . $enrollment->id,
            ['academic_year' => '2027-2028', 'semester' => '2nd']
        );

        $response->assertStatus(422);
    }

    public function test_path_d_allows_term_correction_on_a_passed_subject(): void
    {
        $this->pass('PROG1', '2026-2027', 1);

        $enrollment = Enrollment::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects['PROG1']->id,
            'academic_year' => '2026-2027',
            'semester'      => 1,
            'year_level'    => 1,
            'status'        => 'Passed',
        ]);

        $this->putJson(
            '/api/staff/students/' . $this->student->student_id . '/enrollments/' . $enrollment->id,
            ['academic_year' => '2025-2026']
        )->assertStatus(200);
    }

    public function test_path_d_status_only_change_is_unaffected(): void
    {
        $enrollment = Enrollment::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects['PROG1']->id,
            'academic_year' => '2026-2027',
            'semester'      => 1,
            'year_level'    => 1,
            'status'        => 'Enrolled',
        ]);

        $this->putJson(
            '/api/staff/students/' . $this->student->student_id . '/enrollments/' . $enrollment->id,
            ['status' => 'dropped']
        )->assertStatus(200);
    }

    // ------------------------------------------- Path D: statuses and semester (#76)

    private function pathDEnrollment(string $status = 'Enrolled'): Enrollment
    {
        return Enrollment::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects['PROG1']->id,
            'academic_year' => '2026-2027',
            'semester'      => 1,
            'year_level'    => 1,
            'status'        => $status,
        ]);
    }

    private function updatePathD(Enrollment $enrollment, array $payload)
    {
        return $this->putJson(
            '/api/staff/students/' . $this->student->student_id . '/enrollments/' . $enrollment->id,
            $payload
        );
    }

    public function test_path_d_accepts_the_status_the_enrollment_already_has(): void
    {
        $enrollment = $this->pathDEnrollment('Failed');

        $this->updatePathD($enrollment, ['academic_year' => '2026-2027', 'semester' => '1st', 'status' => 'Failed'])
            ->assertStatus(200);

        $this->assertSame('Failed', $enrollment->fresh()->status);
    }

    public function test_path_d_matches_statuses_without_case_and_stores_the_canonical_form(): void
    {
        $enrollment = $this->pathDEnrollment();

        $this->updatePathD($enrollment, ['status' => 'inc'])->assertStatus(200);
        $this->assertSame('INC', $enrollment->fresh()->status);

        $this->updatePathD($enrollment, ['status' => 'cancelled'])->assertStatus(200);
        $this->assertSame('Cancelled', $enrollment->fresh()->status);
    }

    public function test_path_d_maps_the_older_status_values(): void
    {
        $enrollment = $this->pathDEnrollment();

        $this->updatePathD($enrollment, ['status' => 'dropped'])->assertStatus(200);
        $this->assertSame('DRP', $enrollment->fresh()->status);

        $this->updatePathD($enrollment, ['status' => 'enrolled'])->assertStatus(200);
        $this->assertSame('Enrolled', $enrollment->fresh()->status);
    }

    public function test_path_d_refuses_statuses_with_no_meaning_here(): void
    {
        $enrollment = $this->pathDEnrollment('Failed');

        foreach (['completed', 'archived', 'bogus'] as $status) {
            $this->updatePathD($enrollment, ['status' => $status])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        $this->assertSame('Failed', $enrollment->fresh()->status);
    }

    public function test_path_d_stores_the_semester_as_1_or_2(): void
    {
        $enrollment = $this->pathDEnrollment();

        $this->updatePathD($enrollment, ['academic_year' => '2027-2028', 'semester' => '1st'])->assertStatus(200);
        $this->assertSame('1', (string) $enrollment->fresh()->semester);
        $this->assertSame('2027-2028', $enrollment->fresh()->academic_year);

        $this->updatePathD($enrollment, ['academic_year' => '2027-2028', 'semester' => '2nd Semester'])->assertStatus(200);
        $this->assertSame('2', (string) $enrollment->fresh()->semester);
    }
}
