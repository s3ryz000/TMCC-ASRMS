<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #90: the Request Documents page shows an approved request's appointment
 * and its approval slip, and a rejected request's reason — for the
 * signed-in student's own requests only.
 */
class StudentRequestViewTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $account;
    private Student $student;
    private Student $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $program = $this->makeProgram();
        $this->account = $this->makeUser('student', '260001');
        $this->student = $this->makeStudent($program, ['student_number' => '260001'], $this->account);
        $this->other = $this->makeStudent($program, ['student_number' => '260002', 'email' => 'other@tmcc.test'], $this->makeUser('student', '260002'));

        Sanctum::actingAs($this->account, ['*']);
    }

    private function request(Student $student, string $status, array $extra = []): RecordRequest
    {
        return RecordRequest::create(array_merge([
            'student_id' => $student->student_id, 'record_type' => 'transcript', 'purpose' => 'Employment abroad',
            'copies' => 1, 'status' => $status, 'requested_at' => now()->subDays(3),
        ], $extra));
    }

    public function test_own_requests_carry_the_appointment_and_the_rejection_reason(): void
    {
        $approved = $this->request($this->student, 'approved', [
            'processed_at' => now()->subDay(), 'appointment_at' => '2026-10-12 10:00:00',
        ]);
        $rejected = $this->request($this->student, 'rejected', [
            'processed_at' => now()->subDay(), 'rejection_reason' => 'Unpaid clearance at the library.',
        ]);
        $this->request($this->other, 'approved', ['appointment_at' => '2026-10-12 11:00:00']);

        $rows = collect($this->getJson('/api/student/record-requests')->assertOk()->json('data'))->keyBy('id');

        $this->assertCount(2, $rows, 'Only the signed-in student\'s requests are listed.');
        $this->assertNotNull($rows[$approved->id]['appointment_at']);
        $this->assertSame('2026-10-12 10:00', \Carbon\Carbon::parse($rows[$approved->id]['appointment_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame('Unpaid clearance at the library.', $rows[$rejected->id]['rejection_reason']);
    }

    public function test_the_student_downloads_the_slip_of_their_own_approved_request(): void
    {
        $approved = $this->request($this->student, 'approved', [
            'processed_at' => now()->subDay(), 'appointment_at' => now()->addDays(2)->setTime(10, 0),
        ]);

        $response = $this->get("/api/student/record-requests/{$approved->id}/approval-slip")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_no_slip_for_another_students_request_or_a_pending_one(): void
    {
        $theirs = $this->request($this->other, 'approved', ['appointment_at' => now()->addDays(2)->setTime(10, 0)]);
        $pending = $this->request($this->student, 'pending');

        $this->assertContains($this->get("/api/student/record-requests/{$theirs->id}/approval-slip")->status(), [403, 404]);
        $this->assertSame(422, $this->get("/api/student/record-requests/{$pending->id}/approval-slip")->status());
    }
}
