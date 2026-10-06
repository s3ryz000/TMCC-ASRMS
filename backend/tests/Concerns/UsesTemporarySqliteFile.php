<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A throwaway SQLite *file* database and folders for backup and restore tests
 * (#62, #64). Unlike RefreshDatabase there is no wrapping transaction (SQLite
 * can't VACUUM INTO inside one), and unlike the in-memory test database a
 * restore can replace the file. The live database.sqlite is never touched.
 */
trait UsesTemporarySqliteFile
{
    protected string $tmp;

    protected string $databaseFile;

    protected function useTemporarySqliteFile(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'asrms-test-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp);
        $this->databaseFile = $this->tmp . DIRECTORY_SEPARATOR . 'live.sqlite';
        touch($this->databaseFile);

        config(['database.connections.sqlite.database' => $this->databaseFile]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        config([
            'asrms.backup.path' => $this->tmp . DIRECTORY_SEPARATOR . 'backups',
            'asrms.backup.files' => $this->tmp . DIRECTORY_SEPARATOR . 'files',
            'asrms.backup.log' => $this->tmp . DIRECTORY_SEPARATOR . 'backup.log',
            // A restore turns maintenance mode on; keep that in memory, not in storage/framework.
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
        ]);
        File::ensureDirectoryExists($this->tmp . DIRECTORY_SEPARATOR . 'files');
    }

    protected function dropTemporarySqliteFile(): void
    {
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        if (isset($this->tmp) && str_contains($this->tmp, 'asrms-test-')) {
            File::deleteDirectory($this->tmp);
        }
    }
}
