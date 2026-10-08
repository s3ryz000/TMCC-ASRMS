<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #89: students are told in the portal when a document request is approved
 * (with the appointment), rejected (with the reason) or released, and when a
 * profile update is approved, rejected or returned for revision. Each student
 * sees and marks only their own notifications.
 */
class StudentNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoStudentRecords();
    }

    private function notificationsOf(string $key)
    {
        return DatabaseNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $this->people[$key]['user']->id)
            ->get();
    }

    private function onlyNotificationOf(string $key): DatabaseNotification
    {
        $all = $this->notificationsOf($key);
        $this->assertCount(1, $all, "Student {$key} should have exactly one notification.");
        $this->assertCount(0, $this->notificationsOf($key === 'A' ? 'B' : 'A'), 'The other student was notified.');
        $this->assertSame(1, DatabaseNotification::count());

        return $all->first();
    }

    private function pendingRequest(string $key, string $type = 'transcript'): RecordRequest
    {
        return RecordRequest::create([
            'student_id' => $this->people[$key]['student']->student_id, 'record_type' => $type, 'purpose' => 'Employment',
            'copies' => 1, 'status' => RecordRequest::STATUS_PENDING, 'requested_at' => now(),
        ]);
    }

    /** A future office slot (never a Sunday) at 9:00 AM office time. */
    private function slot(): Carbon
    {
        $day = Carbon::now('Asia/Manila')->addDays(4)->setTime(9, 0);

        return $day->isSunday() ? $day->addDay() : $day;
    }

    // ------------------------------------------------------ document requests

    public function test_approving_a_document_request_notifies_the_student_with_the_appointment(): void
    {
        $request = $this->pendingRequest('A');
        $slot = $this->slot();
        Sanctum::actingAs($this->registrar, ['*']);

        $this->patchJson("/api/staff/requests/{$request->id}/approve", ['appointment_at' => $slot->toIso8601String()])->assertOk();

        $n = $this->onlyNotificationOf('A');
        $this->assertSame(
            'Your Transcript of Records request was approved. Release: ' . $slot->format('D j M Y') . ', 9:00 AM.',
            $n->data['message'],
        );
        $this->assertSame('/dashboard/request', $n->data['link']);
        $this->assertSame('approved', $n->data['status']);
        $this->assertNull($n->read_at);
    }

    public function test_rejecting_a_document_request_notifies_the_student_with_the_reason(): void
    {
        $request = $this->pendingRequest('B', 'certificate_of_grades');
        Sanctum::actingAs($this->registrar, ['*']);

        $this->patchJson("/api/staff/requests/{$request->id}/reject", ['rejection_reason' => 'Unpaid clearance.'])->assertOk();

        $n = $this->onlyNotificationOf('B');
        $this->assertSame('Your Certificate of Grades request was rejected. Reason: Unpaid clearance.', $n->data['message']);
        $this->assertSame('/dashboard/request', $n->data['link']);
    }

    public function test_releasing_a_document_notifies_the_student(): void
    {
        $request = $this->pendingRequest('A');
        $request->update(['status' => RecordRequest::STATUS_APPROVED, 'appointment_at' => $this->slot()]);
        Sanctum::actingAs($this->registrar, ['*']);

        $this->putJson("/api/staff/requests/{$request->id}/release")->assertOk();

        $n = $this->onlyNotificationOf('A');
        $this->assertSame('Your Transcript of Records request was released to you.', $n->data['message']);
        $this->assertSame('released', $n->data['status']);
    }

    public function test_a_refused_decision_notifies_nobody(): void
    {
        // Already released: approve, reject and release are all refused.
        $released = $this->people['A']['request'];
        Sanctum::actingAs($this->registrar, ['*']);

        $this->patchJson("/api/staff/requests/{$released->id}/approve", ['appointment_at' => $this->slot()->toIso8601String()])->assertStatus(422);
        $this->patchJson("/api/staff/requests/{$released->id}/reject", ['rejection_reason' => 'x'])->assertStatus(422);
        $this->putJson("/api/staff/requests/{$released->id}/release")->assertStatus(422);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['A']['update']->id}/return", ['remarks' => ''])->assertStatus(422);

        $this->assertSame(0, DatabaseNotification::count());
    }

    // -------------------------------------------------------- profile updates

    public function test_approving_a_profile_update_notifies_the_student(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['A']['update']->id}/approve")->assertOk();

        $n = $this->onlyNotificationOf('A');
        $this->assertSame('Your profile update (address) was approved.', $n->data['message']);
        $this->assertSame('/dashboard/sis', $n->data['link']);
    }

    public function test_rejecting_a_profile_update_notifies_the_student_with_the_reason(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['B']['update']->id}/reject", ['rejection_reason' => 'Proof does not match.'])->assertOk();

        $n = $this->onlyNotificationOf('B');
        $this->assertSame('Your profile update (address) was rejected. Reason: Proof does not match.', $n->data['message']);
    }

    public function test_returning_a_profile_update_notifies_the_student_with_the_remarks(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['A']['update']->id}/return", ['remarks' => 'Attach a clear barangay certificate.'])->assertOk();

        $n = $this->onlyNotificationOf('A');
        $this->assertSame(
            'Your profile update (address) was returned for revision. Remarks: Attach a clear barangay certificate. Correct it and resubmit on the SIS page.',
            $n->data['message'],
        );
        $this->assertSame('revision_required', $n->data['status']);
        $this->assertSame('/dashboard/sis', $n->data['link']);
    }

    // -------------------------------------------------------------- the bell

    public function test_the_student_lists_their_notifications_newest_first_with_the_unread_count(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        Carbon::setTestNow(now()->subMinutes(5));
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['A']['update']->id}/return", ['remarks' => 'Attach proof.'])->assertOk();
        Carbon::setTestNow();
        $approved = $this->pendingRequest('A');
        $approved->update(['status' => RecordRequest::STATUS_APPROVED, 'appointment_at' => $this->slot()]);
        $this->putJson("/api/staff/requests/{$approved->id}/release")->assertOk();

        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('data.0.kind', 'record_request')
            ->assertJsonPath('data.0.status', 'released')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.1.kind', 'profile_update')
            ->assertJsonPath('data.1.link', '/dashboard/sis');
    }

    public function test_notifications_are_paginated(): void
    {
        $user = $this->people['A']['user'];
        foreach (range(1, 17) as $i) {
            $user->notify(new \App\Notifications\StudentPortalNotice('record_request', $i, 'approved', "Notice {$i}", '/dashboard/request'));
        }
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/student/notifications')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('last_page', 2)->assertJsonPath('unread_count', 17);
        $this->getJson('/api/student/notifications?page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_student_marks_one_or_all_as_read(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['A']['update']->id}/approve")->assertOk();
        $this->patchJson("/api/staff/requests/{$this->pendingRequest('A')->id}/reject", ['rejection_reason' => 'x'])->assertOk();
        [$first, $second] = $this->notificationsOf('A')->all();

        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->patchJson("/api/student/notifications/{$first->id}/read")->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);

        $this->patchJson('/api/student/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($second->fresh()->read_at);
        $this->getJson('/api/student/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_a_student_can_neither_read_nor_mark_another_students_notifications(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->people['B']['update']->id}/reject", ['rejection_reason' => 'Proof for B only.'])->assertOk();
        $bNotice = $this->notificationsOf('B')->first();

        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $body = $this->getJson('/api/student/notifications')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('unread_count', 0);
        $this->assertStringNotContainsString('Proof for B only.', $body->getContent());

        $this->patchJson("/api/student/notifications/{$bNotice->id}/read")->assertNotFound();
        $this->patchJson('/api/student/notifications/read-all')->assertOk();
        $this->assertNull($bNotice->fresh()->read_at);
    }

    public function test_staff_and_admins_get_403_on_the_student_endpoints(): void
    {
        $this->people['A']['user']->notify(new \App\Notifications\StudentPortalNotice('record_request', 1, 'approved', 'Hello', '/dashboard/request'));
        $id = $this->notificationsOf('A')->first()->id;

        foreach ([$this->registrar, $this->admin] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/student/notifications')->assertForbidden();
            $this->patchJson("/api/student/notifications/{$id}/read")->assertForbidden();
            $this->patchJson('/api/student/notifications/read-all')->assertForbidden();
        }
        $this->assertNull(DatabaseNotification::find($id)->read_at);
    }
}
