<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\Student;
use App\Support\ProcessingTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #84: the admin report export answered 500 on SQLite as soon as any request
 * had been processed (MySQL-only TIMESTAMPDIFF), and the error body could show
 * the SQL and the database path.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->student = $this->makeStudent($this->makeProgram());
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
    }

    private function processedRequest(string $requestedAt, string $processedAt, string $status = RecordRequest::STATUS_APPROVED): RecordRequest
    {
        return RecordRequest::create([
            'student_id'   => $this->student->student_id,
            'record_type'  => 'transcript',
            'purpose'      => 'Employment',
            'copies'       => 1,
            'status'       => $status,
            'requested_at' => $requestedAt,
            'processed_at' => $processedAt,
        ]);
    }

    public function test_export_works_on_sqlite_with_processed_requests(): void
    {
        $this->assertSame('sqlite', \DB::connection()->getDriverName());

        $this->processedRequest('2026-09-01 08:00:00', '2026-09-03 09:00:00');                                // 2 days
        $this->processedRequest('2026-09-01 08:00:00', '2026-09-06 07:00:00', RecordRequest::STATUS_REJECTED); // 4 days 23 h → 4
        $this->processedRequest('2026-09-02 08:00:00', '2026-09-02 17:00:00');                                // same day → 0

        $this->getJson('/api/admin/reports/export')
            ->assertOk()
            ->assertJsonCount(3, 'export_data')
            ->assertJsonPath('summary.avg_processing_time_days', 2);
    }

    public function test_the_average_is_rounded_to_two_places(): void
    {
        $this->processedRequest('2026-09-01 08:00:00', '2026-09-02 08:00:00'); // 1
        $this->processedRequest('2026-09-01 08:00:00', '2026-09-02 08:00:00'); // 1
        $this->processedRequest('2026-09-01 08:00:00', '2026-09-03 08:00:00'); // 2

        $this->getJson('/api/admin/reports/export')->assertOk()->assertJsonPath('summary.avg_processing_time_days', 1.33);
    }

    public function test_unprocessed_requests_are_left_out_of_the_average(): void
    {
        RecordRequest::create([
            'student_id' => $this->student->student_id, 'record_type' => 'transcript', 'purpose' => 'Employment',
            'copies' => 1, 'status' => RecordRequest::STATUS_PENDING, 'requested_at' => '2026-08-01 08:00:00',
        ]);

        $this->getJson('/api/admin/reports/export')->assertOk()->assertJsonPath('summary.avg_processing_time_days', null);

        $this->processedRequest('2026-09-01 08:00:00', '2026-09-04 08:00:00');

        $this->getJson('/api/admin/reports/export')->assertOk()->assertJsonPath('summary.avg_processing_time_days', 3);
    }

    /**
     * The days are counted in PHP from the two timestamps, so MySQL gives the
     * same figure as SQLite: whole days rounded down, as TIMESTAMPDIFF(DAY).
     */
    public function test_days_are_counted_the_same_way_whatever_the_database(): void
    {
        $at = fn (string $s) => Carbon::parse($s);

        $this->assertSame(0, ProcessingTime::wholeDays($at('2026-09-01 08:00'), $at('2026-09-02 07:59:59')));
        $this->assertSame(1, ProcessingTime::wholeDays($at('2026-09-01 08:00'), $at('2026-09-02 08:00')));
        $this->assertSame(30, ProcessingTime::wholeDays($at('2026-09-01 08:00'), $at('2026-10-01 09:00')));
        $this->assertSame(2.5, ProcessingTime::averageDays([
            [$at('2026-09-01'), $at('2026-09-03')],
            [$at('2026-09-01'), $at('2026-09-04')],
            [null, $at('2026-09-04')],
        ]));
        $this->assertNull(ProcessingTime::averageDays([]));

        foreach (['Http/Controllers/ReportController.php', 'Services/ReportFigures.php'] as $file) {
            $source = file_get_contents(app_path($file));
            foreach (['TIMESTAMPDIFF', 'julianday', 'DATEDIFF', 'selectRaw', 'DB::raw'] as $driverSpecific) {
                $this->assertStringNotContainsString($driverSpecific, $source, $file);
            }
        }
    }

    public function test_a_report_error_does_not_show_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        \DB::statement('DROP TABLE system_logs');

        $response = $this->getJson('/api/staff/reports/transaction-history')->assertStatus(500);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('system_logs', $response->getContent());
    }
}
