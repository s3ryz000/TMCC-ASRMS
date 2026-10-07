<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * A5: a student submits a records request, registrar staff review it (approve
 * with an appointment slot, or reject), and an approved request is released.
 */
class RecordRequestFlowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $studentUser;
    private User $staff;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1]]);

        $this->staff = $this->makeUser('staff');
        $this->admin = $this->makeUser('admin');
        $this->studentUser = $this->makeUser('student', '2026-0001');
        $this->student = $this->makeStudent($this->program, [], $this->studentUser);
    }

    /**
     * A future office-hours slot, as the approval modal sends it. A Sunday is
     * moved to the Monday after, since Sundays are refused (#80).
     */
    private function slot(int $daysAhead = 3, int $hour = 9): string
    {
        $day = Carbon::now('Asia/Manila')->addDays($daysAhead);
        if ($day->isSunday()) {
            $day->addDay();
        }

        return $day->setTime($hour, 0)->toIso8601String();
    }

    /** The next given weekday (Carbon::SUNDAY, Carbon::MONDAY, ...) at least two days ahead. */
    private function next(int $weekday, int $hour = 9): Carbon
    {
        return Carbon::now('Asia/Manila')->addDays(2)->startOfDay()->next($weekday)->setTime($hour, 0);
    }

    private function submit(array $payload = ['record_type' => 'transcript', 'purpose' => 'Employment'])
    {
        Sanctum::actingAs($this->studentUser, ['*']);

        return $this->postJson('/api/student/record-requests', $payload);
    }

    private function pendingRequest(string $type = 'transcript'): RecordRequest
    {
        return RecordRequest::findOrFail($this->submit(['record_type' => $type, 'purpose' => 'Employment'])->assertCreated()->json('record_request.id'));
    }

    private function approve(RecordRequest $request, ?string $slot = null, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->staff, ['*']);

        return $this->patchJson("/api/staff/requests/{$request->id}/approve", ['appointment_at' => $slot ?? $this->slot()]);
    }

    // ----------------------------------------------------------------- submit

    public function test_student_submits_a_request(): void
    {
        $this->submit()
            ->assertCreated()
            ->assertJsonPath('record_request.status', 'pending')
            ->assertJsonPath('record_request.record_type', 'transcript')
            ->assertJsonPath('record_request.student_id', $this->student->student_id);

        $this->assertDatabaseHas('system_logs', ['action' => 'Document request submitted', 'user_id' => $this->studentUser->id]);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $this->submit()->assertCreated();

        $this->submit()->assertStatus(422)->assertJsonPath('message', 'You already have a pending request for this document.');
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->submit(['record_type' => 'diploma'])->assertStatus(422)->assertJsonValidationErrors('record_type');
    }

    public function test_award_certificate_requires_eligibility(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 2.50, 'Passed');

        $this->submit(['record_type' => 'deans_list_certificate', 'academic_year' => '2026-2027', 'semester' => '1', 'purpose' => 'Scholarship'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not eligible to request this award certificate.');
    }

    public function test_eligible_student_can_request_an_award_certificate(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 1.25, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 1.50, 'Passed');

        $this->submit(['record_type' => 'latin_honor_certificate', 'purpose' => 'Graduation'])
            ->assertCreated()
            ->assertJsonPath('record_request.award_name', 'Magna Cum Laude');
    }

    public function test_student_sees_only_their_own_requests(): void
    {
        $mine = $this->pendingRequest();

        $otherUser = $this->makeUser('student', '2026-0002');
        $other = $this->makeStudent($this->program, ['student_number' => '2026-0002', 'email' => 'other@tmcc.test'], $otherUser);
        $theirs = RecordRequest::create([
            'student_id' => $other->student_id, 'record_type' => 'transcript', 'status' => 'pending', 'requested_at' => now(), 'copies' => 1,
        ]);

        Sanctum::actingAs($this->studentUser, ['*']);
        $ids = collect($this->getJson('/api/student/record-requests')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->getJson("/api/student/record-requests/{$theirs->id}")->assertNotFound();
    }

    // ----------------------------------------------------------------- review

    public function test_staff_approve_with_an_appointment_slot(): void
    {
        $request = $this->pendingRequest();

        $this->approve($request)->assertOk()->assertJsonPath('record_request.status', 'approved');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertNotNull($request->processed_by);
        $this->assertNotNull($request->appointment_at);
    }

    public function test_approval_refuses_a_sunday(): void
    {
        $request = $this->pendingRequest();
        $sunday = $this->next(Carbon::SUNDAY);

        $this->approve($request, $sunday->toIso8601String())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected appointment date is not an office day.');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNull($request->fresh()->appointment_at);
    }

    public function test_a_weekday_9am_slot_saves_as_9am_that_day(): void
    {
        $request = $this->pendingRequest();
        $monday = $this->next(Carbon::MONDAY);
        // Sent the way the modal sends it: the instant in UTC (09:00 Manila = 01:00Z).
        $sent = $monday->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->approve($request, $sent)->assertOk();

        $this->assertSame($monday->format('Y-m-d') . ' 09:00', $request->fresh()->appointment_at->setTimezone('Asia/Manila')->format('Y-m-d H:i'));
    }

    public function test_the_appointment_reads_back_as_booked(): void
    {
        $request = $this->pendingRequest();
        $slot = $this->slot(hour: 14);

        $response = $this->approve($request, $slot)->assertOk();

        $this->assertTrue(Carbon::parse($slot)->equalTo($request->fresh()->appointment_at));
        $this->assertTrue(Carbon::parse($slot)->equalTo(Carbon::parse($response->json('record_request.appointment_at'))));
    }

    public function test_a_booked_slot_shows_as_taken(): void
    {
        $slot = Carbon::parse($this->slot(hour: 14))->setTimezone('Asia/Manila');
        $this->approve($this->pendingRequest(), $slot->toIso8601String())->assertOk();

        $this->getJson('/api/staff/appointment-slots?month=' . $slot->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('taken_by_date.' . $slot->format('Y-m-d'), ['14:00']);
    }

    public function test_a_refused_approval_is_not_logged(): void
    {
        $this->approve($this->pendingRequest(), $this->slot(daysAhead: -1))->assertStatus(422);

        $this->assertDatabaseMissing('system_logs', ['action' => 'Request approved']);
    }

    public function test_approval_needs_a_future_slot(): void
    {
        $this->approve($this->pendingRequest(), $this->slot(daysAhead: -1))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please select a future appointment date and time.');
    }

    public function test_approval_needs_an_office_hours_slot(): void
    {
        $this->approve($this->pendingRequest(), $this->slot(hour: 12))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected appointment time is not available.');
    }

    public function test_a_slot_can_only_be_booked_once(): void
    {
        $slot = $this->slot();
        $first = $this->pendingRequest('transcript');
        $second = $this->pendingRequest('copy_of_grades');

        $this->approve($first, $slot)->assertOk();

        $this->approve($second, $slot)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected appointment slot is already taken.');
    }

    public function test_only_pending_requests_can_be_approved(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request)->assertOk();

        $this->approve($request, $this->slot(daysAhead: 4))->assertStatus(422)->assertJsonPath('message', 'Request is not pending.');
    }

    public function test_staff_reject_with_a_reason(): void
    {
        $request = $this->pendingRequest();
        Sanctum::actingAs($this->staff, ['*']);

        $this->patchJson("/api/staff/requests/{$request->id}/reject", ['rejection_reason' => 'Unsettled balance'])
            ->assertOk()
            ->assertJsonPath('record_request.status', 'rejected')
            ->assertJsonPath('record_request.rejection_reason', 'Unsettled balance');
    }

    public function test_students_cannot_review_requests(): void
    {
        $request = $this->pendingRequest();

        $this->approve($request, as: $this->studentUser)->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    // ---------------------------------------------------------------- release

    public function test_approved_request_is_released_and_logged_as_a_transaction(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request)->assertOk();

        $this->putJson("/api/staff/requests/{$request->id}/release")
            ->assertOk()
            ->assertJsonPath('record_request.status', 'released');

        $this->assertNotNull($request->fresh()->released_at);
        $this->assertDatabaseHas('record_transactions', [
            'student_id'       => $this->student->student_id,
            'transaction_type' => 'document_release',
            'status'           => 'completed',
        ]);
    }

    public function test_pending_request_cannot_be_released(): void
    {
        $request = $this->pendingRequest();
        Sanctum::actingAs($this->staff, ['*']);

        $this->putJson("/api/staff/requests/{$request->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only approved requests can be released.');

        $this->assertDatabaseCount('record_transactions', 0);
    }

    public function test_a_refused_release_is_not_logged(): void
    {
        $request = $this->pendingRequest();
        Sanctum::actingAs($this->staff, ['*']);

        $this->putJson("/api/staff/requests/{$request->id}/release")->assertStatus(422);

        $this->assertDatabaseMissing('system_logs', ['action' => 'Document released']);
    }

    public function test_a_release_is_logged(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request)->assertOk();

        $this->putJson("/api/staff/requests/{$request->id}/release")->assertOk();

        $this->assertDatabaseHas('system_logs', ['action' => 'Document released', 'user_id' => $this->staff->id]);
    }

    public function test_migration_corrects_appointments_stored_as_utc(): void
    {
        $request = $this->pendingRequest();
        // How the old approve endpoint stored a 2:00 PM Manila booking.
        \Illuminate\Support\Facades\DB::table('record_requests')->where('id', $request->id)
            ->update(['status' => 'approved', 'appointment_at' => '2026-10-05 06:00:00']);

        $migration = require database_path('migrations/2026_09_26_000002_store_appointments_in_office_time.php');
        $migration->up();

        $this->assertSame('2026-10-05 14:00:00', $request->fresh()->appointment_at->toDateTimeString());

        $migration->down();
        $this->assertSame('2026-10-05 06:00:00', \Illuminate\Support\Facades\DB::table('record_requests')->where('id', $request->id)->value('appointment_at'));
    }

    public function test_admin_keeps_document_release(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request, as: $this->admin)->assertOk();

        $this->putJson("/api/staff/requests/{$request->id}/release")->assertOk();
    }

    public function test_released_requests_appear_in_the_student_history(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request)->assertOk();
        $this->putJson("/api/staff/requests/{$request->id}/release")->assertOk();

        Sanctum::actingAs($this->studentUser, ['*']);
        $this->getJson("/api/student/record-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('record_request.status', 'released');
    }
}
