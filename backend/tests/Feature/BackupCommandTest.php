<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Services\Backup\BackupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\Concerns\UsesTemporarySqliteFile;
use Tests\TestCase;
use ZipArchive;

/**
 * #62: php artisan asrms:backup writes the database and the uploaded files to
 * one zip with a manifest, keeps 7 daily / 4 weekly / 12 monthly copies, and
 * records every run (backup.log, system_logs, the stored last result). Every
 * test uses temporary folders; the live database is never touched.
 */
class BackupCommandTest extends TestCase
{
    // A temporary SQLite file, not RefreshDatabase: SQLite can't VACUUM INTO
    // inside the transaction RefreshDatabase wraps each test in.
    use UsesTemporarySqliteFile;
    use SeedsTwoStudentRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporarySqliteFile();
        $this->seedTwoStudentRecords();

        File::ensureDirectoryExists($this->tmp . '/files/student-documents/1');
        file_put_contents($this->tmp . '/files/student-documents/1/form137.pdf', '%PDF-1.4 form 137');
        file_put_contents($this->tmp . '/files/proof.pdf', '%PDF-1.4 proof');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropTemporarySqliteFile();
        parent::tearDown();
    }

    private function sets(string $folder): array
    {
        $files = array_map('basename', glob($this->tmp . "/backups/{$folder}/asrms-*.zip") ?: []);
        sort($files);

        return $files;
    }

    public function test_a_backup_holds_the_database_the_files_and_a_manifest(): void
    {
        Carbon::setTestNow('2026-10-07 18:00:00'); // a Wednesday

        $this->artisan('asrms:backup')->expectsOutputToContain('Backup completed')->assertExitCode(0);

        $this->assertSame(['asrms-20261007-1800.zip'], $this->sets('daily'));
        $zipPath = $this->tmp . '/backups/daily/asrms-20261007-1800.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['database/database.sqlite', 'files/proof.pdf', 'files/student-documents/1/form137.pdf', 'manifest.json'], $names);

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $expectedCounts = ['students' => 2, 'enrollments' => 4, 'grades' => 4, 'users' => 4, 'record_requests' => 2];
        $this->assertSame($expectedCounts, $manifest['counts']);
        $this->assertSame('daily', $manifest['label']);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame(2, $manifest['files']['count']);

        // The copy is a working database with the same rows.
        $copy = $this->tmp . '/copy.sqlite';
        file_put_contents($copy, $zip->getFromName('database/database.sqlite'));
        $zip->close();
        $pdo = new PDO('sqlite:' . $copy);
        foreach ($expectedCounts as $table => $count) {
            $this->assertSame($count, (int) $pdo->query("select count(*) from {$table}")->fetchColumn(), $table);
        }
        $this->assertSame('Anabel', $pdo->query("select first_name from students where student_number = '260001'")->fetchColumn());
        $pdo = null;

        // It passes the restore check, and the run is recorded everywhere.
        $this->assertSame($manifest, app(BackupService::class)->verify($zipPath));
        $this->assertMatchesRegularExpression('/^\[2026-10-07 18:00:00\] OK daily asrms-20261007-1800\.zip /', file_get_contents($this->tmp . '/backup.log'));
        $log = SystemLog::latest('log_id')->first();
        $this->assertStringStartsWith('Backup completed: daily asrms-20261007-1800.zip', $log->action);
        $this->assertNull($log->user_id);
        $this->assertSame('system', $log->role);
        $this->assertTrue(app(BackupService::class)->lastSuccess()['ok']);
        $this->assertSame('asrms-20261007-1800.zip', app(BackupService::class)->lastRun()['file']);
    }

    public function test_retention_keeps_7_daily_4_weekly_and_promotes_sundays_and_month_starts(): void
    {
        $day = Carbon::parse('2026-08-01 18:00:00'); // a Saturday and the 1st
        for ($i = 0; $i < 70; $i++, $day->addDay()) {
            Carbon::setTestNow($day->copy());
            $this->assertTrue(app(BackupService::class)->run('daily')->ok);
        }
        // Last run: 2026-10-09.
        $this->assertSame(
            ['asrms-20261003-1800.zip', 'asrms-20261004-1800.zip', 'asrms-20261005-1800.zip', 'asrms-20261006-1800.zip',
             'asrms-20261007-1800.zip', 'asrms-20261008-1800.zip', 'asrms-20261009-1800.zip'],
            $this->sets('daily'),
        );
        // The newest 4 Sunday backups.
        $this->assertSame(['asrms-20260913-1800.zip', 'asrms-20260920-1800.zip', 'asrms-20260927-1800.zip', 'asrms-20261004-1800.zip'], $this->sets('weekly'));
        // Every 1st of the month so far (fewer than 12).
        $this->assertSame(['asrms-20260801-1800.zip', 'asrms-20260901-1800.zip', 'asrms-20261001-1800.zip'], $this->sets('monthly'));
    }

    public function test_monthly_copies_are_capped_at_12_and_pruning_stays_in_its_folder(): void
    {
        $service = app(BackupService::class);
        $monthly = $this->tmp . '/backups/monthly';
        File::ensureDirectoryExists($monthly);
        for ($m = 1; $m <= 20; $m++) {
            touch(sprintf('%s/asrms-%04d%02d01-1800.zip', $monthly, 2025 + intdiv($m - 1, 12), (($m - 1) % 12) + 1));
        }
        file_put_contents($monthly . '/notes.txt', 'keep me');
        file_put_contents($this->tmp . '/asrms-20200101-1800.zip', 'outside the backup folder');

        $deleted = $service->prune($monthly, 12);

        $this->assertCount(8, $deleted);
        $this->assertCount(12, $this->sets('monthly'));
        $this->assertSame('asrms-20250901-1800.zip', $this->sets('monthly')[0]);
        $this->assertFileExists($monthly . '/notes.txt');
        // A folder outside the backup root is never pruned.
        $this->assertSame([], $service->prune($this->tmp, 0));
        $this->assertFileExists($this->tmp . '/asrms-20200101-1800.zip');
    }

    public function test_the_pre_update_label_keeps_its_own_10_copies(): void
    {
        $day = Carbon::parse('2026-10-01 09:00:00');
        for ($i = 0; $i < 12; $i++, $day->addMinutes(5)) {
            Carbon::setTestNow($day->copy());
            $this->artisan('asrms:backup', ['--label' => 'pre-update'])->assertExitCode(0);
        }

        $this->assertCount(10, $this->sets('pre-update'));
        $this->assertSame([], $this->sets('daily'));
        $this->assertSame([], $this->sets('weekly'));
    }

    public function test_a_failure_is_logged_and_exits_non_zero(): void
    {
        Carbon::setTestNow('2026-10-07 18:00:00');
        $this->artisan('asrms:backup')->assertExitCode(0);
        $lastGood = app(BackupService::class)->lastSuccess();

        // The backup "drive" is a file, so no folder can be created there.
        file_put_contents($this->tmp . '/not-a-folder', 'x');
        config(['asrms.backup.path' => $this->tmp . DIRECTORY_SEPARATOR . 'not-a-folder' . DIRECTORY_SEPARATOR . 'backups']);
        Carbon::setTestNow('2026-10-08 18:00:00');

        $this->artisan('asrms:backup')->expectsOutputToContain('Backup FAILED')->assertExitCode(1);

        $this->assertStringContainsString('[2026-10-08 18:00:00] FAILED daily: Backup folder', file_get_contents($this->tmp . '/backup.log'));
        $this->assertStringStartsWith('Backup FAILED: daily: Backup folder', SystemLog::latest('log_id')->value('action'));
        $lastRun = app(BackupService::class)->lastRun();
        $this->assertFalse($lastRun['ok']);
        $this->assertSame($lastGood, app(BackupService::class)->lastSuccess());
    }

    public function test_backups_are_never_written_inside_public_or_the_files_they_back_up(): void
    {
        config(['asrms.backup.path' => public_path('backups')]);
        $this->artisan('asrms:backup')->assertExitCode(1);
        $this->assertDirectoryDoesNotExist(public_path('backups'));

        config(['asrms.backup.path' => $this->tmp . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'backups']);
        $this->artisan('asrms:backup')->assertExitCode(1);

        $this->assertStringContainsString('must not be inside', file_get_contents($this->tmp . '/backup.log'));
    }
}
