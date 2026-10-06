<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Student;
use App\Models\SystemLog;
use App\Services\Backup\BackupService;
use App\Services\OfficialTranscriptExportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\Concerns\UsesTemporarySqliteFile;
use Tests\TestCase;
use ZipArchive;

/**
 * #64: php artisan asrms:restore <file> --force puts a backup back: the same
 * rows, grades, academic progress, transcript and uploaded files. It refuses
 * without --force and refuses a damaged backup before touching anything, and
 * always takes a pre-restore backup of the current data first. Everything
 * runs on a temporary database and folders.
 */
class RestoreCommandTest extends TestCase
{
    use UsesTemporarySqliteFile;
    use SeedsTwoStudentRecords;

    private string $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporarySqliteFile();
        $this->seedTwoStudentRecords();

        $this->files = $this->tmp . DIRECTORY_SEPARATOR . 'files';
        File::ensureDirectoryExists($this->files . '/student-documents/1');
        file_put_contents($this->files . '/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->files . '/student-documents/1/form137.pdf', '%PDF-1.4 form 137');
        file_put_contents($this->files . '/proof.pdf', '%PDF-1.4 proof');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropTemporarySqliteFile();
        parent::tearDown();
    }

    private function backup(): string
    {
        Carbon::setTestNow('2026-10-07 18:00:00');
        $this->artisan('asrms:backup')->assertExitCode(0);

        return $this->tmp . '/backups/daily/asrms-20261007-1800.zip';
    }

    /** What a restore must bring back for student A. */
    private function snapshot(): array
    {
        $a = $this->people['A']['student']->student_id;
        Sanctum::actingAs($this->registrar, ['*']);
        $progress = $this->getJson("/api/staff/students/{$a}/academic-progress")->assertOk()->json();

        $build = new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml');
        $build->setAccessible(true);
        $transcript = $build->invoke(app(OfficialTranscriptExportService::class), Student::findOrFail($a), 'DOC-1', 'NOW');

        return [
            'counts' => app(BackupService::class)->counts(),
            'grades' => Grade::whereHas('enrollment', fn ($q) => $q->where('student_id', $a))->orderBy('grade_id')->pluck('grade_value')->all(),
            'progress' => $progress,
            'transcript' => $transcript,
        ];
    }

    private function fileTree(): array
    {
        $tree = [];
        foreach (File::allFiles($this->files, true) as $file) {
            $tree[str_replace('\\', '/', $file->getRelativePathname())] = hash_file('sha256', $file->getPathname());
        }
        ksort($tree);

        return $tree;
    }

    private function leftovers(): array
    {
        return array_merge(
            glob($this->tmp . '/files.*') ?: [],
            glob($this->tmp . '/.restore-*') ?: [],
            glob($this->tmp . '/backups/.restore-*') ?: [],
            glob($this->tmp . '/backups/.tmp-*') ?: [],
        );
    }

    public function test_a_restore_brings_back_the_rows_grades_transcript_and_files(): void
    {
        $zip = $this->backup();
        $manifest = app(BackupService::class)->verify($zip);
        $before = $this->snapshot();
        $tree = $this->fileTree();
        $this->assertStringContainsString('1.25', $before['transcript']);

        // Things go wrong after the backup.
        $a = $this->people['A']['student']->student_id;
        $b = $this->people['B']['student']->student_id;
        Grade::whereHas('enrollment', fn ($q) => $q->where('student_id', $a))->update(['grade_value' => 3.0]);
        Grade::whereHas('enrollment', fn ($q) => $q->where('student_id', $b))->delete();
        DB::table('record_requests')->where('student_id', $b)->delete();
        unlink($this->files . '/proof.pdf');
        file_put_contents($this->files . '/student-documents/1/form137.pdf', 'overwritten');
        file_put_contents($this->files . '/stray.pdf', 'added after the backup');
        $this->assertNotEquals($before['grades'], $this->snapshot()['grades']);

        Carbon::setTestNow('2026-10-07 19:30:00');
        $this->artisan('asrms:restore', ['file' => $zip, '--force' => true])
            ->expectsOutputToContain('Safety backup: pre-restore/asrms-20261007-1930.zip')
            ->expectsOutputToContain('Maintenance mode on.')
            ->expectsOutputToContain('Maintenance mode off.')
            ->expectsOutputToContain('Restore completed')
            ->assertExitCode(0);

        // The data is back as it was at the backup.
        $after = $this->snapshot();
        $this->assertSame($before['counts'], $after['counts']);
        $this->assertSame($manifest['counts'], $after['counts']);
        $this->assertSame($before['grades'], $after['grades']);
        $this->assertSame($before['progress'], $after['progress']);
        $this->assertSame($before['transcript'], $after['transcript']);
        $this->assertSame($tree, $this->fileTree());
        $this->assertFileExists($this->files . '/.gitignore');

        // The data as it was before the restore is in the safety backup.
        $safety = $this->tmp . '/backups/pre-restore/asrms-20261007-1930.zip';
        app(BackupService::class)->verify($safety);
        $check = new ZipArchive();
        $check->open($safety);
        $this->assertSame('added after the backup', $check->getFromName('files/stray.pdf'));
        $this->assertSame(1, json_decode($check->getFromName('manifest.json'), true)['counts']['record_requests']);
        $check->close();

        // Logged, maintenance off, nothing left behind, backup history kept.
        $this->assertFalse(app()->maintenanceMode()->active());
        $this->assertSame([], $this->leftovers());
        $this->assertMatchesRegularExpression('/\] RESTORED asrms-20261007-1800\.zip in [\d.]+ s, safety backup pre-restore\/asrms-20261007-1930\.zip, counts match the backup/', file_get_contents($this->tmp . '/backup.log'));
        $this->assertStringStartsWith('Restore completed: asrms-20261007-1800.zip', SystemLog::latest('log_id')->value('action'));
        $this->assertSame('pre-restore', app(BackupService::class)->lastSuccess()['label']);
    }

    public function test_without_force_nothing_changes(): void
    {
        $zip = $this->backup();
        Grade::query()->update(['grade_value' => 3.0]);
        $database = hash_file('sha256', $this->databaseFile);
        $tree = $this->fileTree();

        $this->artisan('asrms:restore', ['file' => $zip])
            ->expectsOutputToContain('is a valid backup')
            ->expectsOutputToContain('Run again with --force')
            ->assertExitCode(1);

        $this->assertSame($database, hash_file('sha256', $this->databaseFile));
        $this->assertSame($tree, $this->fileTree());
        $this->assertDirectoryDoesNotExist($this->tmp . '/backups/pre-restore');
        $this->assertSame([3.0], Grade::query()->distinct()->pluck('grade_value')->map(fn ($v) => (float) $v)->all());
        $this->assertFalse(app()->maintenanceMode()->active());
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_damaged_or_foreign_backup_is_refused_before_anything_is_touched(): void
    {
        $good = $this->backup();
        $make = function (string $name, callable $change) use ($good) {
            $path = $this->tmp . "/{$name}.zip";
            copy($good, $path);
            $zip = new ZipArchive();
            $zip->open($path);
            $change($zip);
            $zip->close();

            return $path;
        };

        $broken = [
            'not a zip' => tap($this->tmp . '/garbage.zip', fn ($p) => file_put_contents($p, 'this is not a zip file')),
            'database altered' => $make('altered-db', fn (ZipArchive $z) => $z->addFromString('database/database.sqlite', 'SQLite format 3 but not really')),
            'file altered' => $make('altered-file', fn (ZipArchive $z) => $z->addFromString('files/proof.pdf', 'tampered')),
            'no manifest' => $make('no-manifest', fn (ZipArchive $z) => $z->deleteName('manifest.json')),
            'unsafe path' => $make('unsafe', fn (ZipArchive $z) => $z->addFromString('files/../../escaped.txt', 'x')),
            'other database type' => $make('mysql', function (ZipArchive $z) {
                $manifest = json_decode($z->getFromName('manifest.json'), true);
                $z->addFromString('manifest.json', json_encode(['driver' => 'mysql'] + $manifest));
            }),
            'missing file' => $this->tmp . '/does-not-exist.zip',
        ];

        Grade::query()->update(['grade_value' => 3.0]);
        $data = $this->snapshot();
        $tree = $this->fileTree();
        $logs = SystemLog::count();

        foreach ($broken as $case => $path) {
            $this->artisan('asrms:restore', ['file' => $path, '--force' => true])
                ->expectsOutputToContain('Restore FAILED')
                ->assertExitCode(1);

            // The only write is the refusal's own system log line.
            $this->assertSame($data, $this->snapshot(), $case);
            $this->assertSame(++$logs, SystemLog::count(), $case);
            $this->assertSame($tree, $this->fileTree(), $case);
            $this->assertDirectoryDoesNotExist($this->tmp . '/backups/pre-restore', $case);
            $this->assertFalse(app()->maintenanceMode()->active(), $case);
            $this->assertSame([], $this->leftovers(), $case);
        }
        $this->assertFileDoesNotExist(dirname($this->tmp) . '/escaped.txt');
        $this->assertSame(7, substr_count(file_get_contents($this->tmp . '/backup.log'), 'RESTORE FAILED'));
        $this->assertStringContainsString('failed while checking the backup', SystemLog::latest('log_id')->value('action'));
    }

    public function test_a_deleted_database_is_restored_and_the_old_files_are_kept_aside(): void
    {
        $zip = $this->backup();
        $before = $this->snapshot();

        // The disaster: the database file is gone.
        DB::disconnect('sqlite');
        unlink($this->databaseFile);
        file_put_contents($this->files . '/stray.pdf', 'added after the backup');

        Carbon::setTestNow('2026-10-07 20:00:00');
        $this->artisan('asrms:restore', ['file' => $zip, '--force' => true])
            ->expectsOutputToContain('No current database file, so there is nothing to back up first.')
            ->expectsOutputToContain('kept in')
            ->assertExitCode(0);

        $after = $this->snapshot();
        $this->assertSame($before, $after);
        $this->assertFileDoesNotExist($this->files . '/stray.pdf');
        $aside = $this->tmp . DIRECTORY_SEPARATOR . 'files.replaced-20261007200000';
        $this->assertFileExists($aside . '/stray.pdf');
        $this->assertStringContainsString('safety backup none (no database)', SystemLog::latest('log_id')->value('action'));
        File::deleteDirectory($aside);
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_site_already_in_maintenance_stays_in_maintenance(): void
    {
        $zip = $this->backup();
        app()->maintenanceMode()->activate(['except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]);

        $this->artisan('asrms:restore', ['file' => $zip, '--force' => true])
            ->expectsOutputToContain('already in maintenance mode')
            ->assertExitCode(0);

        $this->assertTrue(app()->maintenanceMode()->active());
        app()->maintenanceMode()->deactivate();
    }
}
