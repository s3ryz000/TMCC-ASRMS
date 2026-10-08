<?php

namespace Tests\Feature;

use App\Models\PendingStudentUpdate;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #88: the registrar can return a profile update for revision with remarks;
 * the student sees their own requests with the decision and reason, corrects
 * a returned one and resubmits it (the same request goes back to pending).
 */
class ProfileUpdateRevisionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoStudentRecords();
    }

    private function update(string $key = 'A'): PendingStudentUpdate
    {
        return $this->people[$key]['update']->fresh();
    }

    private function returnUpdate(string $key = 'A', string $remarks = 'The barangay certificate is unreadable; attach a clear copy.')
    {
        Sanctum::actingAs($this->registrar, ['*']);

        return $this->patchJson("/api/staff/pending-profile-updates/{$this->update($key)->id}/return", ['remarks' => $remarks]);
    }

    private function resubmit(array $fields, ?UploadedFile $file = null, string $key = 'A')
    {
        Sanctum::actingAs($this->people[$key]['user'], ['*']);

        return $this->post("/api/student/profile-updates/{$this->update($key)->id}/resubmit",
            $fields + ($file ? ['supporting_document' => $file] : []), ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------- return for revision

    public function test_the_registrar_returns_an_update_with_remarks_and_it_is_logged(): void
    {
        $this->returnUpdate()->assertOk()->assertJsonPath('update.status', 'revision_required');

        $update = $this->update();
        $this->assertSame(PendingStudentUpdate::STATUS_REVISION_REQUIRED, $update->status);
        $this->assertSame('The barangay certificate is unreadable; attach a clear copy.', $update->rejection_reason);
        $this->assertSame($this->registrar->id, $update->reviewed_by);
        $this->assertNotNull($update->reviewed_at);
        $this->assertSame(['revision_required'], array_column($update->review_history, 'event'));
        // The record itself is untouched until an approval.
        $this->assertSame(self::PERSONAL['A']['address'], $this->people['A']['student']->fresh()->address);
        $this->assertTrue(SystemLog::where('action', 'like', 'Returned for revision profile update for 260001%')->exists());
    }

    public function test_remarks_are_required_to_return_an_update(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->patchJson("/api/staff/pending-profile-updates/{$this->update()->id}/return", ['remarks' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->assertSame('pending', $this->update()->status);
    }

    public function test_only_a_pending_update_can_be_returned(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->update()->id}/approve")->assertOk();

        $this->returnUpdate()->assertStatus(422);
        $this->assertSame('approved', $this->update()->status);
    }

    // ------------------------------------------------- the student's own list

    public function test_the_student_sees_the_returned_request_with_the_remarks(): void
    {
        $this->returnUpdate();
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $this->getJson('/api/student/profile-updates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->update()->id)
            ->assertJsonPath('data.0.status', 'revision_required')
            ->assertJsonPath('data.0.reason', 'The barangay certificate is unreadable; attach a clear copy.')
            ->assertJsonPath('data.0.fields.0.field', 'address')
            ->assertJsonPath('data.0.fields.0.label', 'address')
            ->assertJsonPath('data.0.fields.0.old', self::PERSONAL['A']['address'])
            ->assertJsonPath('data.0.fields.0.new', 'New address A')
            ->assertJsonPath('data.0.has_supporting_document', true)
            ->assertJsonMissingPath('data.0.supporting_document_path');

        $this->assertNotNull($this->getJson('/api/student/profile-updates')->json('data.0.decided_at'));
        $this->assertNotNull($this->getJson('/api/student/profile-updates')->json('data.0.submitted_at'));
    }

    public function test_a_student_never_sees_or_resubmits_another_students_request(): void
    {
        $this->returnUpdate('B');
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $body = $this->getJson('/api/student/profile-updates')->assertOk();
        $this->assertSame([$this->update('A')->id], array_column($body->json('data'), 'id'));
        $this->assertStringNotContainsString('New address B', $body->getContent());
        $this->assertStringNotContainsString(self::PERSONAL['B']['address'], $body->getContent());

        $this->post("/api/student/profile-updates/{$this->update('B')->id}/resubmit", ['address' => 'Hijacked'], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->assertSame('revision_required', $this->update('B')->status);
        $this->assertSame('New address B', $this->update('B')->new_values['address']);
    }

    // ------------------------------------------------------------- resubmit

    public function test_a_returned_request_is_corrected_and_goes_back_to_pending(): void
    {
        $this->returnUpdate();
        $id = $this->update()->id;
        $count = PendingStudentUpdate::count();

        $this->resubmit(['address' => 'Blk 3 Lot 4 Cabezas Trece Martires'], UploadedFile::fake()->create('clear-proof.pdf', 20, 'application/pdf'))
            ->assertOk();

        $update = $this->update();
        $this->assertSame($id, $update->id);
        $this->assertSame($count, PendingStudentUpdate::count());
        $this->assertSame('pending', $update->status);
        $this->assertSame(['address' => 'Blk 3 Lot 4 Cabezas Trece Martires'], $update->new_values);
        $this->assertSame(['address' => self::PERSONAL['A']['address']], $update->old_values);
        $this->assertNull($update->rejection_reason);
        $this->assertNull($update->reviewed_by);
        $this->assertNull($update->reviewed_at);
        // History keeps the registrar's remarks.
        $this->assertSame(['revision_required', 'resubmitted'], array_column($update->review_history, 'event'));
        $this->assertSame('The barangay certificate is unreadable; attach a clear copy.', $update->review_history[0]['remarks']);
        // The new document replaced the old one.
        $this->assertSame('clear-proof.pdf', $update->supporting_document_original_name);
        Storage::disk('local')->assertExists($update->supporting_document_path);
        Storage::disk('local')->assertMissing('pending-profile-updates/proof-A.pdf');
        $this->assertTrue(SystemLog::where('action', 'like', 'Student 260001 % resubmitted a returned profile update%')->exists());

        // The student's list shows it pending again, and the registrar's pending list has it.
        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->getJson('/api/student/profile-updates')->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.reason', null);
        Sanctum::actingAs($this->registrar, ['*']);
        $this->assertContains($id, array_column($this->getJson('/api/staff/pending-profile-updates?status=pending')->json(), 'id'));
    }

    public function test_resubmitting_without_a_new_document_keeps_the_attached_one(): void
    {
        $this->returnUpdate();

        $this->resubmit(['address' => 'Blk 3 Lot 4 Cabezas Trece Martires'])->assertOk();

        $this->assertSame('pending-profile-updates/proof-A.pdf', $this->update()->supporting_document_path);
        Storage::disk('local')->assertExists('pending-profile-updates/proof-A.pdf');
    }

    public function test_a_resubmitted_request_can_then_be_approved(): void
    {
        $this->returnUpdate();
        $this->resubmit(['address' => 'Blk 3 Lot 4 Cabezas Trece Martires'])->assertOk();

        Sanctum::actingAs($this->registrar, ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->update()->id}/approve")->assertOk();

        $this->assertSame('Blk 3 Lot 4 Cabezas Trece Martires', $this->people['A']['student']->fresh()->address);
        $this->assertSame(['revision_required', 'resubmitted', 'approved'], array_column($this->update()->review_history, 'event'));
        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->getJson('/api/student/profile-updates')->assertJsonPath('data.0.status', 'approved');
    }

    public function test_only_a_returned_request_can_be_resubmitted(): void
    {
        $this->resubmit(['address' => 'Blk 3 Lot 4 Cabezas Trece Martires'])->assertStatus(422);

        $this->assertSame('pending', $this->update()->status);
        $this->assertSame('New address A', $this->update()->new_values['address']);
    }

    public function test_a_resubmission_must_change_something(): void
    {
        $this->returnUpdate();

        $this->resubmit(['address' => self::PERSONAL['A']['address']])->assertStatus(422);
        $this->assertSame('revision_required', $this->update()->status);
    }

    // ------------------------------------------------ approve/reject unchanged

    public function test_approve_and_reject_work_as_before_and_record_history(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->patchJson("/api/staff/pending-profile-updates/{$this->update('A')->id}/approve")->assertOk();
        $this->assertSame('approved', $this->update('A')->status);
        $this->assertSame('New address A', $this->people['A']['student']->fresh()->address);
        $this->assertTrue(SystemLog::where('action', 'like', 'Approved profile update for 260001%')->exists());

        $this->patchJson("/api/staff/pending-profile-updates/{$this->update('B')->id}/reject", ['rejection_reason' => 'Document does not match.'])->assertOk();
        $this->assertSame('rejected', $this->update('B')->status);
        $this->assertSame('Document does not match.', $this->update('B')->rejection_reason);
        $this->assertSame(self::PERSONAL['B']['address'], $this->people['B']['student']->fresh()->address);
        $this->assertTrue(SystemLog::where('action', 'like', 'Rejected profile update for 260002%')->exists());

        Sanctum::actingAs($this->people['B']['user'], ['*']);
        $this->getJson('/api/student/profile-updates')
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.reason', 'Document does not match.');
    }

    // ------------------------------------------------------- list and roles

    public function test_the_list_filters_by_status(): void
    {
        $this->returnUpdate('B');
        Sanctum::actingAs($this->registrar, ['*']);

        $this->assertSame([$this->update('A')->id], array_column($this->getJson('/api/staff/pending-profile-updates?status=pending')->assertOk()->json(), 'id'));
        $this->assertSame([$this->update('B')->id], array_column($this->getJson('/api/staff/pending-profile-updates?status=revision_required')->assertOk()->json(), 'id'));
        $this->assertSame([], $this->getJson('/api/staff/pending-profile-updates?status=approved')->assertOk()->json());
        $this->assertCount(2, $this->getJson('/api/staff/pending-profile-updates')->assertOk()->json());
        $this->getJson('/api/staff/pending-profile-updates?status=bogus')->assertStatus(422);
    }

    public function test_admins_list_by_status_but_cannot_decide(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/staff/pending-profile-updates?status=pending')->assertOk()->assertJsonCount(2);
        foreach (['approve', 'reject', 'return'] as $action) {
            $this->patchJson("/api/staff/pending-profile-updates/{$this->update()->id}/{$action}", ['remarks' => 'x'])->assertForbidden();
        }
        $this->assertSame('pending', $this->update()->status);
    }

    public function test_students_cannot_decide_and_staff_cannot_use_the_student_endpoints(): void
    {
        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->patchJson("/api/staff/pending-profile-updates/{$this->update()->id}/return", ['remarks' => 'x'])->assertForbidden();

        $this->returnUpdate();
        foreach ([$this->registrar, $this->admin] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/student/profile-updates')->assertForbidden();
            $this->postJson("/api/student/profile-updates/{$this->update()->id}/resubmit", ['address' => 'x'])->assertForbidden();
        }
        $this->assertSame('revision_required', $this->update()->status);
    }
}
