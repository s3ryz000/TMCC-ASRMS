<?php

namespace Tests\Feature;

use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\GradeSubmissionItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\SystemLog;
use App\Models\User;
use App\Support\AcademicStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Grade double approval (#107): staff encode, a registrar with
 * "approve-grades" (not the encoder) approves. Until then nothing that reads
 * grades (GWA, prerequisites, the student portal) sees the encoded values.
 */
class GradeApprovalTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $studentUser;
    private User $encoder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1], 'C' => [1, 2]], ['C' => ['A']]);
        $this->studentUser = $this->makeUser('student', '260001');
        $this->student = $this->makeStudent($this->program, ['student_number' => '260001'], $this->studentUser);

        $this->encoder = $this->makeUser('staff', 'encoder');
        Sanctum::actingAs($this->encoder, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
    }

    private function grade(string $code): Grade
    {
        return Grade::where('student_id', $this->student->student_id)
            ->where('subject_id', $this->subjects[$code]->id)
            ->latest('id')
            ->firstOrFail();
    }

    /** Grade entry as the Edit Student page submits it, without approving. */
    private function encode(array $grades): TestResponse
    {
        $rows = [];
        foreach ($grades as $code => $value) {
            $rows[] = is_string($value)
                ? ['grade_id' => $this->grade($code)->id, 'status' => $value, 'grade_value' => null]
                : ['grade_id' => $this->grade($code)->id, 'grade_value' => $value];
        }

        return $this->putJson("/api/staff/students/{$this->student->student_id}/grades/bulk-update", ['grades' => $rows]);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function url(int $id, string $action = ''): string
    {
        return "/api/staff/grade-submissions/{$id}" . ($action ? "/{$action}" : '');
    }

    private function termOf(array $progress): array
    {
        return $progress['grouped_enrollments'][0];
    }

    /** What the student portal shows. */
    private function studentView(): array
    {
        $this->as($this->studentUser);
        $view = [];
        foreach (['/api/student/grades', '/api/student/academic-summary', '/api/student/curriculum'] as $uri) {
            $view[$uri] = $this->getJson($uri)->assertOk()->getContent();
        }
        $this->as($this->encoder);

        return $view;
    }

    // ------------------------------------------------------------- pending

    public function test_a_submitted_grade_changes_nothing_until_it_is_approved(): void
    {
        $studentBefore = $this->studentView();
        $gpaBefore = $this->student->fresh()->GPA;

        $this->encode(['A' => 1.25, 'B' => 1.50])
            ->assertStatus(202)
            ->assertJsonPath('message', '2 grade(s) submitted for approval.')
            ->assertJsonPath('submission.status', 'pending');

        // The grades table, the enrollment, the GWA and the prerequisite are untouched.
        $this->assertNull($this->grade('A')->grade_value);
        $this->assertSame(AcademicStatus::ENROLLED, $this->grade('A')->status);
        $this->assertDatabaseHas('enrollments', ['subject_id' => $this->subjects['A']->id, 'status' => AcademicStatus::ENROLLED]);
        $this->assertSame($gpaBefore, $this->student->fresh()->GPA);
        $this->assertSame(0, EnrollmentAuditLog::whereIn('action', ['grade_updated'])->count());
        $this->assertNotContains($this->subjects['A']->id, app(\App\Services\Enrollment\AcademicRecordQuery::class)->passedSubjectIds($this->student));

        // The student sees exactly what they saw before, and nothing about the submission.
        $studentAfter = $this->studentView();
        $this->assertSame($studentBefore, $studentAfter);
        foreach ($studentAfter as $uri => $body) {
            $this->assertStringNotContainsString('pending_submission', $body, $uri);
            $this->assertStringNotContainsString('1.25', $body, $uri);
        }

        // The encoder's screen shows the proposed values.
        $term = $this->termOf($this->academicProgress($this->student));
        $this->assertSame('pending', $term['pending_submission']['status']);
        $this->assertSame('Staff User', $term['pending_submission']['submitted_by']['name']);
        $this->assertEqualsCanonicalizing(['1.25', '1.50'], array_column($term['pending_submission']['items'], 'grade_value'));
        $this->assertNull($term['last_returned']);

        $this->assertDatabaseHas('system_logs', ['action' => 'Submitted 2 grade(s) for approval for student 260001 (Juan Dela Cruz), A.Y. 2026-2027 sem 1']);
    }

    // ------------------------------------------------------------- approve

    public function test_approving_applies_every_item_with_its_audit_rows_and_recomputes_the_gwa(): void
    {
        $id = $this->encode(['A' => 1.25, 'B' => 1.50])->assertStatus(202)->json('submission.id');

        $this->approvePendingGrades()->assertOk()->assertJsonPath('submission.status', 'approved');

        $this->assertSame('1.25', $this->grade('A')->grade_value);
        $this->assertSame(AcademicStatus::PASSED, $this->grade('B')->status);
        $this->assertDatabaseHas('enrollments', ['subject_id' => $this->subjects['A']->id, 'status' => AcademicStatus::PASSED]);
        $this->assertSame(2, EnrollmentAuditLog::where('action', 'grade_updated')->count());
        $this->assertSame($this->gradeApprover()->id, EnrollmentAuditLog::where('action', 'grade_updated')->value('changed_by'));
        $this->assertSame('1.38', $this->student->fresh()->GPA);
        $this->assertContains($this->subjects['A']->id, app(\App\Services\Enrollment\AcademicRecordQuery::class)->passedSubjectIds($this->student));

        $submission = GradeSubmission::findOrFail($id);
        $this->assertSame('approved', $submission->status);
        $this->assertSame($this->gradeApprover()->id, (int) $submission->decided_by);
        $this->assertNotNull($submission->decided_at);
        $this->assertTrue(SystemLog::where('action', 'like', 'Approved 2 grade(s) for student 260001 (Juan Dela Cruz)%')->exists());

        $this->assertNull($this->termOf($this->academicProgress($this->student))['pending_submission']);
    }

    public function test_the_approver_sees_the_queue_and_old_and_new_values(): void
    {
        $id = $this->encode(['A' => 2.00])->json('submission.id');
        $this->as($this->gradeApprover());

        $this->getJson('/api/staff/grade-submissions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_number', '260001')
            ->assertJsonPath('data.0.student_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.0.program', 'BSIT')
            ->assertJsonPath('data.0.submitted_by.name', 'Staff User')
            ->assertJsonPath('data.0.item_count', 1);

        $this->getJson($this->url($id))
            ->assertOk()
            ->assertJsonPath('data.items.0.subject_code', 'A')
            ->assertJsonPath('data.items.0.units', 3)
            ->assertJsonPath('data.items.0.old.grade_value', null)
            ->assertJsonPath('data.items.0.old.status', 'Enrolled')
            ->assertJsonPath('data.items.0.new.grade_value', '2.00')
            ->assertJsonPath('data.items.0.new.status', 'Passed');

        $this->getJson('/api/staff/grade-submissions?status=approved')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ who may

    public function test_the_encoder_cannot_approve_their_own_grades(): void
    {
        $this->encoder->givePermissionTo(Permission::findOrCreate(User::APPROVE_GRADES, 'api'));
        $id = $this->encode(['A' => 2.00])->json('submission.id');

        $this->patchJson($this->url($id, 'approve'))
            ->assertForbidden()
            ->assertJsonPath('message', "You can't approve grades you encoded.");

        $this->assertNull($this->grade('A')->grade_value);
        $this->assertSame('pending', GradeSubmission::findOrFail($id)->status);
    }

    public function test_only_staff_with_the_permission_reach_the_approver_endpoints(): void
    {
        $id = $this->encode(['A' => 2.00])->json('submission.id');
        $endpoints = [
            ['GET', '/api/staff/grade-submissions'],
            ['GET', $this->url($id)],
            ['PATCH', $this->url($id, 'approve')],
            ['PATCH', $this->url($id, 'return')],
        ];

        $otherStaff = $this->makeUser('staff', 'other.staff');
        foreach ([$otherStaff, $this->makeUser('admin'), $this->studentUser] as $user) {
            $this->as($user);
            foreach ($endpoints as [$method, $uri]) {
                $this->json($method, $uri, ['remarks' => 'x'])->assertForbidden();
            }
        }
        $this->as($otherStaff);
        $this->getJson('/api/staff/grade-submissions')->assertJsonPath('message', "You don't have access to grade approvals.");

        $this->assertSame('pending', GradeSubmission::findOrFail($id)->status);
    }

    // ------------------------------------------------------ return, resubmit

    public function test_returning_needs_remarks_which_the_encoder_sees_and_a_resubmission_is_new(): void
    {
        $id = $this->encode(['A' => 2.00])->json('submission.id');
        $this->as($this->gradeApprover());

        $this->patchJson($this->url($id, 'return'), ['remarks' => ''])->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->patchJson($this->url($id, 'return'), ['remarks' => str_repeat('x', 501)])->assertStatus(422);
        $this->patchJson($this->url($id, 'return'), ['remarks' => 'A should be 2.25 per the class record.'])->assertOk();

        $this->assertSame('returned', GradeSubmission::findOrFail($id)->status);
        $this->assertNull($this->grade('A')->grade_value);
        $this->assertTrue(SystemLog::where('action', 'like', 'Returned 1 grade(s) to the encoder for student 260001%')->exists());
        $this->patchJson($this->url($id, 'approve'))->assertStatus(409);

        $this->as($this->encoder);
        $term = $this->termOf($this->academicProgress($this->student));
        $this->assertNull($term['pending_submission']);
        $this->assertSame('A should be 2.25 per the class record.', $term['last_returned']['remarks']);
        $this->assertSame('Staff User', $term['last_returned']['decided_by']);

        $newId = $this->encode(['A' => 2.25])->assertStatus(202)->json('submission.id');
        $this->assertNotSame($id, $newId);
        $this->assertSame('returned', GradeSubmission::findOrFail($id)->status);
        $this->assertNull($this->termOf($this->academicProgress($this->student))['last_returned']);

        $this->approvePendingGrades()->assertOk();
        $this->assertSame('2.25', $this->grade('A')->grade_value);
    }

    public function test_the_encoder_can_cancel_a_pending_submission_and_nobody_else_can(): void
    {
        $id = $this->encode(['A' => 2.00])->json('submission.id');

        $this->as($this->gradeApprover());
        $this->patchJson($this->url($id, 'cancel'))->assertForbidden();

        $this->as($this->encoder);
        $this->patchJson($this->url($id, 'cancel'))->assertOk();
        $this->assertSame('cancelled', GradeSubmission::findOrFail($id)->status);
        $this->patchJson($this->url($id, 'cancel'))->assertStatus(409);
        $this->assertNull($this->termOf($this->academicProgress($this->student))['pending_submission']);
        $this->assertNull($this->grade('A')->grade_value);
    }

    public function test_the_same_encoder_resubmitting_replaces_the_items_and_another_user_gets_409(): void
    {
        $id = $this->encode(['A' => 2.00, 'B' => 2.00])->json('submission.id');

        $this->encode(['A' => 1.75])->assertStatus(202)->assertJsonPath('submission.id', $id);
        $this->assertSame(1, GradeSubmission::count());
        $this->assertSame(2, GradeSubmissionItem::count());
        $this->assertSame('1.75', GradeSubmissionItem::where('grade_id', $this->grade('A')->id)->value('grade_value'));

        $this->as($this->makeUser('staff', 'second.encoder'));
        $this->encode(['B' => 3.00])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Grades for this term are waiting for approval.');
        $this->assertSame(1, GradeSubmission::count());
        $this->assertSame('2.00', GradeSubmissionItem::where('grade_id', $this->grade('B')->id)->value('grade_value'));
    }

    // ---------------------------------------------------------- refusals

    public function test_an_invalid_row_is_refused_at_submit_time_and_nothing_is_created(): void
    {
        $this->submitGrades($this->student, ['A' => 4.00])->assertOk(); // Conditional, approved
        $count = GradeSubmission::count();

        $this->encode(['A' => 2.50, 'B' => 2.00])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Grade update failed.')
            ->assertJsonFragment(['Grade #' . $this->grade('A')->id . ': a Conditional (4.0) grade can only be resolved to 3.00 (Passed) or 5.00 (Failed).']);

        $this->assertSame($count, GradeSubmission::count());
        $this->assertSame(0, GradeSubmissionItem::whereHas('submission', fn ($q) => $q->where('status', 'pending'))->count());
    }

    public function test_approval_rolls_back_entirely_when_one_item_cannot_be_applied(): void
    {
        $id = $this->encode(['A' => 1.50, 'B' => 2.50])->json('submission.id');
        $audits = EnrollmentAuditLog::count();

        // B changed after it was submitted (e.g. corrected another way).
        Grade::whereKey($this->grade('B')->id)->update(['status' => AcademicStatus::INC, 'remarks' => 'Incomplete']);

        $this->as($this->gradeApprover());
        $this->patchJson($this->url($id, 'approve'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'B changed after these grades were submitted. Return this submission so it can be encoded again.');

        $this->assertNull($this->grade('A')->grade_value);
        $this->assertSame(AcademicStatus::ENROLLED, $this->grade('A')->status);
        $this->assertDatabaseHas('enrollments', ['subject_id' => $this->subjects['A']->id, 'status' => AcademicStatus::ENROLLED]);
        $this->assertSame($audits, EnrollmentAuditLog::count());
        $this->assertSame('pending', GradeSubmission::findOrFail($id)->status);
    }

    // ------------------------------------------------------------ no bypass

    public function test_no_staff_endpoint_changes_a_grade_value_or_status_without_approval(): void
    {
        $grade = $this->grade('A');
        $base = "/api/staff/students/{$this->student->student_id}";

        $this->putJson("{$base}/grades/bulk-update", ['grades' => [['grade_id' => $grade->id, 'grade_value' => 1.00]]])->assertStatus(202);
        $this->patchJson($this->url(GradeSubmission::latest('id')->value('id'), 'cancel'))->assertOk();

        $this->putJson("{$base}/grades/{$grade->id}", ['grade_value' => 1.00])->assertStatus(202)->assertJsonPath('grade.grade_value', null);
        $this->patchJson($this->url(GradeSubmission::latest('id')->value('id'), 'cancel'))->assertOk();

        $this->putJson("{$base}/grades/{$grade->id}", ['status' => 'INC'])->assertStatus(202);
        $this->patchJson($this->url(GradeSubmission::latest('id')->value('id'), 'cancel'))->assertOk();

        $this->putJson("{$base}/grades/{$grade->id}", ['remarks' => 'Dropped'])->assertStatus(202);
        $this->patchJson($this->url(GradeSubmission::latest('id')->value('id'), 'cancel'))->assertOk();

        // The enrollment endpoint never touches the grade; results are read from grades.
        $this->putJson("{$base}/enrollments/{$grade->enrollment_id}", ['status' => 'Passed']);
        $this->assertNotContains($this->subjects['A']->id, app(\App\Services\Enrollment\AcademicRecordQuery::class)->passedSubjectIds($this->student));

        $grade->refresh();
        $this->assertNull($grade->grade_value);
        $this->assertSame(AcademicStatus::ENROLLED, $grade->status);
        $this->assertSame(0, EnrollmentAuditLog::whereIn('action', ['grade_updated', 'inc_to_passed', 'marked_credited'])->count());
    }

    public function test_a_new_grade_with_a_value_is_created_empty_and_its_value_waits(): void
    {
        $this->enrollNextTermWithoutGrade();

        $response = $this->postJson("/api/staff/students/{$this->student->student_id}/grades", [
            'subject_id' => $this->subjects['C']->id, 'academic_year' => '2026-2027', 'semester' => '2', 'grade_value' => 1.75,
        ])->assertCreated()
            ->assertJsonPath('message', 'Grade added. Its value is waiting for approval.')
            ->assertJsonPath('submission.status', 'pending');

        $grade = Grade::findOrFail($response->json('grade.id'));
        $this->assertNull($grade->grade_value);
        $this->assertSame(AcademicStatus::ENROLLED, $grade->status);

        $this->approvePendingGrades()->assertOk();
        $this->assertSame('1.75', $grade->fresh()->grade_value);
    }

    public function test_a_grade_waiting_for_approval_keeps_its_term_and_its_enrollment(): void
    {
        $grade = $this->grade('A');
        $this->encode(['A' => 2.00])->assertStatus(202);
        $base = "/api/staff/students/{$this->student->student_id}";

        $this->putJson("{$base}/grades/{$grade->id}", ['semester' => '2'])->assertStatus(409);
        $this->deleteJson("{$base}/enrollments/{$grade->enrollment_id}", ['reason' => 'Wrong subject'])->assertStatus(409);

        $this->assertSame('1', $grade->fresh()->semester);
        $this->assertModelExists($grade);
    }

    // ------------------------------------------------------- who approves

    public function test_the_admin_grants_and_revokes_can_approve_grades_for_staff_only(): void
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff', 'registrar.head');
        $this->as($admin);
        $payload = fn (User $u, array $extra) => array_merge([
            'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'department' => 'Registrar', 'status' => 'active',
        ], $extra);

        $this->getJson("/api/admin/users/{$staff->id}")->assertJsonPath('user.can_approve_grades', false);

        $this->putJson("/api/admin/users/{$staff->id}", $payload($staff, ['can_approve_grades' => true]))
            ->assertOk()
            ->assertJsonPath('user.can_approve_grades', true);
        $this->assertTrue($staff->fresh()->can_approve_grades);
        $this->assertDatabaseHas('system_logs', ['action' => 'User updated: registrar.head: can approve grades on']);
        $this->getJson('/api/admin/users')->assertJsonFragment(['username' => 'registrar.head', 'can_approve_grades' => true]);

        // Saving without the field keeps it.
        $this->putJson("/api/admin/users/{$staff->id}", $payload($staff, []))->assertJsonPath('user.can_approve_grades', true);

        $this->putJson("/api/admin/users/{$staff->id}", $payload($staff, ['can_approve_grades' => false]))
            ->assertOk()
            ->assertJsonPath('user.can_approve_grades', false);
        $this->assertDatabaseHas('system_logs', ['action' => 'User updated: registrar.head: can approve grades off']);

        $other = $this->makeUser('admin', 'admin02');
        $this->putJson("/api/admin/users/{$other->id}", $payload($other, ['can_approve_grades' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('can_approve_grades');
        $this->assertFalse($other->fresh()->checkPermissionTo(User::APPROVE_GRADES, 'api'));
    }

    public function test_a_staff_user_moved_to_another_role_loses_the_permission(): void
    {
        $staff = $this->makeUser('staff', 'registrar.head');
        $staff->givePermissionTo(Permission::findOrCreate(User::APPROVE_GRADES, 'api'));
        $this->as($this->makeUser('admin'));

        $this->putJson("/api/admin/users/{$staff->id}", [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'admin', 'department' => 'MIS', 'status' => 'active',
        ])->assertOk()->assertJsonPath('user.can_approve_grades', false);

        $this->assertFalse($staff->fresh()->checkPermissionTo(User::APPROVE_GRADES, 'api'));
    }

    public function test_the_signed_in_user_says_whether_they_can_approve_grades(): void
    {
        $this->getJson('/api/user')->assertOk()->assertJsonPath('can_approve_grades', false);

        $this->as($this->gradeApprover());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('can_approve_grades', true);
    }

    // ------------------------------------------------------------ migration

    public function test_the_migration_rolls_back_and_forward(): void
    {
        $migration = 'database/migrations/2026_10_22_000001_create_grade_submissions_tables.php';

        Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
        $this->assertFalse(Schema::hasTable('grade_submissions'));
        $this->assertFalse(Schema::hasTable('grade_submission_items'));
        $this->assertTrue(Schema::hasTable('grades'));

        Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
        $this->assertTrue(Schema::hasTable('grade_submissions'));
        $this->assertTrue(Schema::hasColumns('grade_submission_items', ['grade_submission_id', 'grade_id', 'grade_value', 'status', 'remarks', 'supporting_document_reference', 'old_value']));
    }

    /** An enrollment in C (2nd semester) without a grade row, so storeGrade can create one. */
    private function enrollNextTermWithoutGrade(): void
    {
        \App\Models\Enrollment::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['C']->id,
            'academic_year' => '2026-2027', 'semester' => '2', 'year_level' => 1, 'status' => AcademicStatus::ENROLLED,
        ]);
    }
}
