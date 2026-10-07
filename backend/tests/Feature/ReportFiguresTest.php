<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #96: request and activity reports for a date range, a correct approval
 * rate and the admin dashboard totals, each checked against seeded data.
 */
class ReportFiguresTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const SEPTEMBER = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    private Student $student;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->student = $this->makeStudent($this->makeProgram());
        $this->admin = $this->makeUser('admin');
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function request(string $status, string $type, string $requestedAt, ?string $processedAt = null): RecordRequest
    {
        return RecordRequest::create([
            'student_id'   => $this->student->student_id,
            'record_type'  => $type,
            'purpose'      => 'Employment',
            'copies'       => 1,
            'status'       => $status,
            'requested_at' => $requestedAt,
            'processed_at' => $processedAt,
        ]);
    }

    private function log(string $action, string $role, string $at): void
    {
        $log = new SystemLog(['action' => $action, 'role' => $role, 'user_id' => null]);
        $log->created_at = $at;
        $log->updated_at = $at;
        $log->save();
    }

    /** Five requests made in September and two just outside it. */
    private function seedRequests(): void
    {
        $this->request('approved', 'transcript', '2026-09-01 08:00:00', '2026-09-03 08:00:00');            // 2 days
        $this->request('released', 'transcript', '2026-09-05 08:00:00', '2026-09-06 10:00:00');            // 1
        $this->request('released', 'certificate_of_grades', '2026-09-10 08:00:00', '2026-09-13 09:00:00'); // 3
        $this->request('rejected', 'transcript', '2026-09-12 08:00:00', '2026-09-12 15:00:00');            // 0
        $this->request('pending', 'copy_of_grades', '2026-09-30 23:30:00');                                // last day, included
        $this->request('approved', 'transcript', '2026-10-01 00:00:00', '2026-10-02 00:00:00');            // day after
        $this->request('rejected', 'transcript', '2026-08-31 23:59:59', '2026-09-01 08:00:00');            // day before
    }

    // ── Requests report ─────────────────────────────────────────────────────

    public function test_requests_report_counts_per_status_and_record_type_in_the_range(): void
    {
        $this->seedRequests();

        $this->getJson('/api/admin/reports/requests?' . http_build_query(self::SEPTEMBER))
            ->assertOk()
            ->assertJsonPath('range', self::SEPTEMBER)
            ->assertJsonPath('total', 5)
            ->assertJsonPath('by_status', ['pending' => 1, 'approved' => 1, 'rejected' => 1, 'released' => 2])
            ->assertJsonPath('by_record_type', [
                ['record_type' => 'transcript', 'total' => 3],
                ['record_type' => 'certificate_of_grades', 'total' => 1],
                ['record_type' => 'copy_of_grades', 'total' => 1],
            ])
            ->assertJsonPath('decided', 4)
            ->assertJsonPath('approval_rate', 75)
            ->assertJsonPath('avg_processing_time_days', 1.5);
    }

    public function test_requests_report_without_a_range_covers_everything(): void
    {
        $this->seedRequests();

        $this->getJson('/api/admin/reports/requests')
            ->assertOk()
            ->assertJsonPath('total', 7)
            ->assertJsonPath('by_status', ['pending' => 1, 'approved' => 2, 'rejected' => 2, 'released' => 2])
            ->assertJsonPath('approval_rate', 66.67);
    }

    public function test_an_empty_range_has_zero_counts_and_no_rates(): void
    {
        $this->seedRequests();

        $this->getJson('/api/admin/reports/requests?date_from=2025-01-01&date_to=2025-01-31')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('by_status', ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'released' => 0])
            ->assertJsonPath('by_record_type', [])
            ->assertJsonPath('approval_rate', null)
            ->assertJsonPath('avg_processing_time_days', null);
    }

    public function test_the_range_is_validated(): void
    {
        $this->getJson('/api/admin/reports/requests?date_from=2026-09-30&date_to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.date_to.0', 'The end date must be on or after the start date.');
        $this->getJson('/api/admin/reports/activity?date_from=30/09/2026')->assertStatus(422)->assertJsonValidationErrors('date_from');
        $this->getJson('/api/admin/reports/export?date_to=yesterday')->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    // ── Approval rate ───────────────────────────────────────────────────────

    /** The pre-UAT run saw 200%: approved + released was divided by approved + rejected. */
    public function test_approval_rate_never_goes_above_100(): void
    {
        $this->request('approved', 'transcript', '2026-09-01 08:00:00', '2026-09-02 08:00:00');
        $this->request('released', 'transcript', '2026-09-01 08:00:00', '2026-09-02 08:00:00');

        $this->getJson('/api/staff/reports/summary')->assertOk()->assertJsonPath('approval_rate', 100);
        $this->getJson('/api/admin/reports/requests')->assertOk()->assertJsonPath('approval_rate', 100);
        $this->getJson('/api/admin/reports/export')->assertOk()->assertJsonPath('summary.approval_rate', 100);

        $this->request('rejected', 'transcript', '2026-09-01 08:00:00', '2026-09-02 08:00:00');
        $this->request('pending', 'transcript', '2026-09-01 08:00:00');

        $this->getJson('/api/staff/reports/summary')->assertOk()->assertJsonPath('approval_rate', 66.67);
    }

    public function test_approval_rate_is_empty_until_something_is_decided(): void
    {
        $this->request('pending', 'transcript', '2026-09-01 08:00:00');

        $this->getJson('/api/staff/reports/summary')->assertOk()->assertJsonPath('approval_rate', null);
    }

    public function test_reading_the_summary_does_not_write_to_the_system_log(): void
    {
        $this->request('approved', 'transcript', '2026-09-01 08:00:00', '2026-09-02 08:00:00');

        $this->getJson('/api/staff/reports/summary')->assertOk();
        $this->getJson('/api/admin/reports/export')->assertOk();

        $this->assertSame(0, SystemLog::count());
    }

    // ── Activity report ─────────────────────────────────────────────────────

    public function test_activity_report_counts_per_day_role_and_action_in_the_range(): void
    {
        $this->log('Request approved', 'staff', '2026-09-01 08:00:00');
        $this->log('Request approved', 'staff', '2026-09-01 09:00:00');
        $this->log('User created', 'admin', '2026-09-01 10:00:00');
        $this->log('Daily backup', 'system', '2026-09-02 12:00:00'); // scheduled job (#62)
        $this->log('Request rejected', 'Staff', '2026-09-30 23:59:00');
        $this->log('Request approved', 'staff', '2026-10-01 00:00:00'); // day after
        $this->log('User created', 'admin', '2026-08-31 23:59:59');     // day before

        $this->getJson('/api/admin/reports/activity?' . http_build_query(self::SEPTEMBER))
            ->assertOk()
            ->assertJsonPath('range', self::SEPTEMBER)
            ->assertJsonPath('total', 5)
            ->assertJsonPath('by_day', [
                ['date' => '2026-09-01', 'total' => 3],
                ['date' => '2026-09-02', 'total' => 1],
                ['date' => '2026-09-30', 'total' => 1],
            ])
            ->assertJsonPath('by_role', [
                ['role' => 'staff', 'total' => 3],
                ['role' => 'admin', 'total' => 1],
                ['role' => 'system', 'total' => 1],
            ])
            ->assertJsonPath('top_actions', [
                ['action' => 'Request approved', 'total' => 2],
                ['action' => 'Daily backup', 'total' => 1],
                ['action' => 'Request rejected', 'total' => 1],
                ['action' => 'User created', 'total' => 1],
            ]);
    }

    public function test_activity_report_lists_at_most_ten_actions(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->log("Action {$i}", 'staff', '2026-09-01 08:00:00');
        }

        $this->getJson('/api/admin/reports/activity?' . http_build_query(self::SEPTEMBER))
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonCount(10, 'top_actions');
    }

    // ── Export uses the same range ──────────────────────────────────────────

    public function test_export_rows_and_summary_use_the_range(): void
    {
        $this->seedRequests();

        $this->getJson('/api/admin/reports/export?' . http_build_query(self::SEPTEMBER))
            ->assertOk()
            ->assertJsonCount(5, 'export_data')
            ->assertJsonPath('summary.total_requests', 5)
            ->assertJsonPath('summary.by_status.released', 2)
            ->assertJsonPath('summary.approval_rate', 75)
            ->assertJsonPath('summary.avg_processing_time_days', 1.5);
    }

    // ── Admin dashboard totals ──────────────────────────────────────────────

    public function test_admin_dashboard_shows_totals_from_the_database(): void
    {
        $this->makeUser('staff', 'staff01');
        $this->makeUser('staff', 'staff02')->update(['status' => 'inactive']);
        $this->makeUser('student', '2026-0001');
        $this->makeUser('admin', 'admin02')->update(['status' => 'inactive']);
        $this->makeStudent(Program::findOrFail($this->student->program_id), ['student_number' => '2026-0002', 'email' => 'ana@tmcc.test']);
        $this->seedRequests();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('totals.users', [
                'admin'   => ['active' => 1, 'inactive' => 1, 'total' => 2],
                'staff'   => ['active' => 1, 'inactive' => 1, 'total' => 2],
                'student' => ['active' => 1, 'inactive' => 0, 'total' => 1],
            ])
            ->assertJsonPath('totals.students', 2)
            ->assertJsonPath('totals.requests', ['pending' => 1, 'approved' => 2, 'rejected' => 2, 'released' => 2]);
    }

    public function test_the_registrar_dashboard_has_no_system_totals(): void
    {
        Sanctum::actingAs($this->makeUser('staff', 'staff01'), ['*']);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('totals')->assertJsonPath('kpis.students_count', 1);
    }
}
