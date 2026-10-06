<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\Concerns\UsesTemporarySqliteFile;
use Tests\TestCase;

/**
 * #65: GET /api/admin/backups/status tells the admin whether backups are
 * working: green after a recent success, red after a failure or when the
 * last success is more than 26 hours old. Admin only.
 */
class BackupStatusTest extends TestCase
{
    use UsesTemporarySqliteFile;
    use SeedsTwoStudentRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporarySqliteFile();
        $this->seedTwoStudentRecords();
        file_put_contents($this->tmp . '/files/proof.pdf', '%PDF-1.4 proof');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropTemporarySqliteFile();
        parent::tearDown();
    }

    private function backupStatus(): array
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->getJson('/api/admin/backups/status')->assertOk()->json();
    }

    public function test_after_a_successful_backup_the_status_is_ok(): void
    {
        Carbon::setTestNow('2026-10-05 18:00:00'); // a Monday
        $this->artisan('asrms:backup')->assertExitCode(0);

        Carbon::setTestNow('2026-10-06 08:30:00');
        $status = $this->backupStatus();

        $this->assertTrue($status['ok']);
        $this->assertFalse($status['stale']);
        $this->assertSame(26, $status['stale_after_hours']);
        $this->assertSame('asrms-20261005-1800.zip', $status['last_success']['file']);
        $this->assertSame('daily', $status['last_success']['folder']);
        $this->assertSame(filesize($this->tmp . '/backups/daily/asrms-20261005-1800.zip'), $status['last_success']['bytes']);
        $this->assertEquals(14.5, $status['last_success']['hours_ago']);
        $this->assertTrue($status['last_run']['ok']);
        $this->assertSame(['daily' => 1, 'weekly' => 0, 'monthly' => 0, 'pre-update' => 0, 'pre-restore' => 0], $status['copies']);
        $this->assertIsInt($status['free_bytes']);
        $this->assertGreaterThan(0, $status['free_bytes']);
        $this->assertSame($this->tmp . DIRECTORY_SEPARATOR . 'backups', $status['location']);
    }

    public function test_a_forced_failure_turns_the_status_red_even_after_a_recent_success(): void
    {
        Carbon::setTestNow('2026-10-05 18:00:00');
        $this->artisan('asrms:backup')->assertExitCode(0);

        // The backup drive goes missing: its path is now a file.
        $good = config('asrms.backup.path');
        file_put_contents($this->tmp . '/drive', 'x');
        config(['asrms.backup.path' => $this->tmp . DIRECTORY_SEPARATOR . 'drive' . DIRECTORY_SEPARATOR . 'backups']);
        Carbon::setTestNow('2026-10-06 18:00:00');
        $this->artisan('asrms:backup')->assertExitCode(1);
        config(['asrms.backup.path' => $good]);

        Carbon::setTestNow('2026-10-06 18:05:00');
        $status = $this->backupStatus();

        $this->assertFalse($status['ok']);
        $this->assertFalse($status['stale']); // the last success is only a day old
        $this->assertFalse($status['last_run']['ok']);
        $this->assertStringContainsString("can't be created", $status['last_run']['message']);
        $this->assertSame('asrms-20261005-1800.zip', $status['last_success']['file']);
    }

    public function test_a_success_older_than_26_hours_is_stale(): void
    {
        Carbon::setTestNow('2026-10-05 18:00:00');
        $this->artisan('asrms:backup')->assertExitCode(0);

        Carbon::setTestNow('2026-10-06 19:59:00'); // 25 h 59 min later
        $this->assertTrue($this->backupStatus()['ok']);

        Carbon::setTestNow('2026-10-06 20:01:00'); // just over 26 h: the 6 PM backup did not run
        $status = $this->backupStatus();
        $this->assertFalse($status['ok']);
        $this->assertTrue($status['stale']);
        $this->assertTrue($status['last_run']['ok']);
    }

    public function test_before_any_backup_the_status_is_red(): void
    {
        $status = $this->backupStatus();

        $this->assertFalse($status['ok']);
        $this->assertTrue($status['stale']);
        $this->assertNull($status['last_success']);
        $this->assertNull($status['last_run']);
        $this->assertSame(0, array_sum($status['copies']));
    }

    public function test_a_backup_entry_shows_as_system_in_the_dashboard_activity(): void
    {
        $this->artisan('asrms:backup')->assertExitCode(0);

        Sanctum::actingAs($this->admin, ['*']);
        $latest = $this->getJson('/api/dashboard')->assertOk()->json('recent_activity.0');

        $this->assertStringStartsWith('Backup completed', $latest['desc']);
        $this->assertSame('System', $latest['user']['name']);
    }

    public function test_only_the_admin_can_see_it(): void
    {
        $this->getJson('/api/admin/backups/status')->assertStatus(401);

        Sanctum::actingAs($this->registrar, ['*']);
        $this->getJson('/api/admin/backups/status')->assertStatus(403);

        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $this->getJson('/api/admin/backups/status')->assertStatus(403);
    }
}
