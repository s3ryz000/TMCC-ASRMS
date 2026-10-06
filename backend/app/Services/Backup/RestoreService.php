<?php

namespace App\Services\Backup;

use App\Models\SystemSetting;
use App\Support\SafeLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Restores a backup set made by asrms:backup (#64), in this order:
 *
 *   1. verify the zip against its manifest (nothing is touched if it fails);
 *   2. take a pre-restore backup of the current data (abort if it fails);
 *   3. extract and re-check the database and files into staging folders;
 *   4. maintenance mode on;
 *   5. replace the database (SQLite: atomic file rename; MySQL: import) and
 *      swap in the uploaded files;
 *   6. php artisan migrate --force;
 *   7. maintenance mode off, and log the result.
 *
 * The only case without a safety backup is a SQLite database file that is
 * already gone (the disaster this exists for); then the current uploaded
 * files are kept aside instead of being deleted.
 */
class RestoreService
{
    /** @var callable(string): void */
    private $say;

    public function __construct(private BackupService $backups)
    {
        $this->say = fn (string $line) => null;
    }

    /** Where progress lines go (the console command prints them). */
    public function reportTo(callable $say): static
    {
        $this->say = $say;

        return $this;
    }

    /**
     * Check that a set can be restored here, without changing anything.
     * Throws with the reason if not.
     */
    public function inspect(string $zipPath): array
    {
        $manifest = $this->backups->verify($zipPath);
        $driver = $this->driver();
        $family = fn (string $d) => in_array($d, ['mysql', 'mariadb'], true) ? 'mysql' : $d;
        if ($family((string) ($manifest['driver'] ?? '')) !== $family($driver)) {
            throw new RuntimeException("The backup is a {$manifest['driver']} database but this installation uses {$driver}.");
        }

        return $manifest;
    }

    /** Row counts now, or null when there is no database to count. */
    public function currentCounts(): ?array
    {
        if ($this->sqliteFileMissing()) {
            return null;
        }
        try {
            return $this->backups->counts();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Restore a set. Returns a report; throws with the reason on failure,
     * after logging it.
     *
     * @return array{manifest: array, before: ?array, after: array, safety: ?BackupResult, kept: ?string, seconds: float}
     */
    public function restore(string $zipPath): array
    {
        $started = microtime(true);
        $at = now();
        $name = basename($zipPath);
        $step = 'checking the backup';
        $staging = null;
        $maintenance = false;
        $replaced = false;

        try {
            $manifest = $this->inspect($zipPath);
            $before = $this->currentCounts();
            ($this->say)("Backup checked: {$name}, made " . ($manifest['created_at'] ?? '?') . ', commit ' . ($manifest['commit'] ?? '?') . '.');

            // 2. Safety backup of what is here now.
            $step = 'taking the safety backup';
            $safety = null;
            $history = [];
            $settingKeys = [];
            if ($this->sqliteFileMissing()) {
                ($this->say)('No current database file, so there is nothing to back up first.');
            } else {
                $safety = $this->backups->run('pre-restore');
                if (! $safety->ok) {
                    throw new RuntimeException("The safety backup failed ({$safety->message}); nothing was restored.");
                }
                ($this->say)("Safety backup: pre-restore/" . basename($safety->file));
                $history = $this->backups->history();
                $settingKeys = $this->settingKeys();
            }

            // 3. Stage everything before touching the live data.
            $step = 'preparing the restore';
            $staging = $this->backups->root() . DIRECTORY_SEPARATOR . '.restore-' . $at->format('YmdHis') . '-' . bin2hex(random_bytes(3));
            File::ensureDirectoryExists($staging);
            $database = $this->extractDatabase($zipPath, $manifest, $staging);
            $incoming = $this->extractFiles($zipPath, $manifest);

            // 4-6.
            $step = 'turning on maintenance mode';
            if (! app()->maintenanceMode()->active()) {
                app()->maintenanceMode()->activate(['except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]);
                $maintenance = true;
                ($this->say)('Maintenance mode on.');
            } else {
                ($this->say)('The site was already in maintenance mode; it stays on afterwards.');
            }

            $step = 'replacing the database';
            $replaced = true;
            $this->replaceDatabase($database);
            ($this->say)('Database restored.');

            $step = 'replacing the uploaded files';
            $kept = $this->swapFiles($incoming, keepOld: $safety === null, at: $at->format('YmdHis'));
            ($this->say)('Uploaded files restored (' . ($manifest['files']['count'] ?? 0) . ').');

            $step = 'running migrations';
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException('php artisan migrate failed: ' . trim(Artisan::output()));
            }
            // Cached settings belong to the old data. (Not Cache::flush(): with the
            // cache maintenance driver that would reopen the site mid-restore.)
            foreach (array_unique(array_merge($settingKeys ?? [], $this->settingKeys())) as $key) {
                Cache::forget("system_setting.{$key}");
            }
            // The restored database has the backup history as of that backup; keep the newer one.
            $this->backups->rememberHistory($history);

            $after = $this->backups->counts();
        } catch (Throwable $e) {
            $reason = $e instanceof RuntimeException && ! $e instanceof QueryException ? $e->getMessage() : SafeLog::describe($e);
            $message = "{$name}: failed while {$step}: {$reason}";
            if ($replaced) {
                $message .= isset($safety) && $safety
                    ? " The previous data is in pre-restore/" . basename($safety->file) . "."
                    : ' There was no previous database to keep.';
            }
            $this->backups->note(sprintf('[%s] RESTORE FAILED %s', $at->format('Y-m-d H:i:s'), $message), "Restore FAILED: {$message}");

            throw new RuntimeException($message, 0, $e);
        } finally {
            if ($maintenance) {
                app()->maintenanceMode()->deactivate();
                ($this->say)('Maintenance mode off.');
            }
            if ($staging !== null && is_dir($staging)) {
                File::deleteDirectory($staging);
            }
            if (isset($incoming) && is_dir($incoming)) {
                File::deleteDirectory($incoming);
            }
            if (isset($database) && is_file($database)) {
                @unlink($database); // still staged: the restore stopped before the swap
            }
        }

        $seconds = microtime(true) - $started;
        $matches = $after === $manifest['counts'];
        $summary = sprintf('%s in %.1f s, safety backup %s, counts %s', $name, $seconds,
            $safety ? "pre-restore/" . basename($safety->file) : 'none (no database)', $matches ? 'match the backup' : 'DIFFER from the backup');
        $this->backups->note(sprintf('[%s] RESTORED %s', $at->format('Y-m-d H:i:s'), $summary), "Restore completed: {$summary}");

        return compact('manifest', 'before', 'after', 'safety', 'kept', 'seconds');
    }

    // ------------------------------------------------------------- internals

    private function driver(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.driver');
    }

    /** @return string[] */
    private function settingKeys(): array
    {
        try {
            return SystemSetting::query()->pluck('key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function sqliteFile(): ?string
    {
        return $this->driver() === 'sqlite' ? (string) config('database.connections.' . config('database.default') . '.database') : null;
    }

    private function sqliteFileMissing(): bool
    {
        $file = $this->sqliteFile();

        return $file !== null && (! is_file($file) || filesize($file) === 0);
    }

    /** Copy the database out of the zip and check it against the manifest again. */
    private function extractDatabase(string $zipPath, array $manifest, string $staging): string
    {
        $entry = 'database/' . $manifest['database']['file'];
        // SQLite is swapped in with a rename, so stage it next to the live file (same drive).
        $target = $this->sqliteFile() !== null
            ? dirname($this->sqliteFile()) . DIRECTORY_SEPARATOR . '.restore-' . bin2hex(random_bytes(4)) . '.sqlite'
            : $staging . DIRECTORY_SEPARATOR . basename($entry);

        $this->extractEntry($zipPath, $entry, $target);
        if (hash_file('sha256', $target) !== $manifest['database']['sha256']) {
            @unlink($target);
            throw new RuntimeException('The extracted database does not match its checksum.');
        }

        return $target;
    }

    /** Extract the uploaded files next to the live folder and check them. */
    private function extractFiles(string $zipPath, array $manifest): string
    {
        $live = $this->backups->filesPath();
        $incoming = $live . '.restoring-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($incoming);
        try {
            return $this->fillFiles($zipPath, $manifest, $live, $incoming);
        } catch (Throwable $e) {
            File::deleteDirectory($incoming);

            throw $e;
        }
    }

    private function fillFiles(string $zipPath, array $manifest, string $live, string $incoming): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The backup file is not a readable zip.');
        }
        $lines = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                    continue;
                }
                if (! BackupService::isSafeEntry($name)) {
                    throw new RuntimeException('The backup contains an unsafe file path.');
                }
                $relative = substr($name, 6);
                $target = $incoming . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($target));
                $this->extractEntry($zipPath, $name, $target);
                $lines[] = $relative . ':' . hash_file('sha256', $target);
            }
        } finally {
            $zip->close();
        }
        sort($lines);
        if (hash('sha256', implode("\n", $lines)) !== $manifest['files']['sha256']) {
            throw new RuntimeException('The extracted files do not match their checksum.');
        }

        // Keep the folder's tracked placeholder, which backups leave out.
        if (is_file($live . DIRECTORY_SEPARATOR . '.gitignore')) {
            copy($live . DIRECTORY_SEPARATOR . '.gitignore', $incoming . DIRECTORY_SEPARATOR . '.gitignore');
        }

        return $incoming;
    }

    private function extractEntry(string $zipPath, string $entry, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The backup file is not a readable zip.');
        }
        try {
            $in = $zip->getStream($entry);
            $out = @fopen($target, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Could not extract {$entry}.");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        } finally {
            $zip->close();
        }
    }

    private function replaceDatabase(string $staged): void
    {
        $connection = config('database.default');

        if ($this->sqliteFile() !== null) {
            $live = $this->sqliteFile();
            if (is_file($live)) {
                try {
                    DB::connection($connection)->statement('PRAGMA wal_checkpoint(TRUNCATE)');
                } catch (Throwable) {
                    // Not in WAL mode, or the file is damaged; it is being replaced either way.
                }
            }
            DB::disconnect($connection);
            DB::purge($connection);
            gc_collect_cycles();

            // A journal left from the old file must not be applied to the restored one.
            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                if (is_file($live . $suffix) && ! @unlink($live . $suffix)) {
                    @unlink($staged);
                    throw new RuntimeException("{$live}{$suffix} is in use; stop the web server and try again.");
                }
            }
            if (! @rename($staged, $live)) {
                @unlink($staged);
                throw new RuntimeException('The database file is in use and could not be replaced; stop the web server and try again. Nothing was changed.');
            }
            DB::purge($connection);

            return;
        }

        // MySQL: empty the database, then load the dump into it.
        $config = config("database.connections.{$connection}");
        $mysql = (string) config('asrms.backup.mysql');
        if (! is_file($mysql)) {
            throw new RuntimeException("mysql not found at {$mysql} (set ASRMS_MYSQL_PATH).");
        }
        Schema::connection($connection)->dropAllTables();
        $credentials = $this->backups->mysqlCredentialsFile(dirname($staged), $config);
        $input = fopen($staged, 'rb');
        try {
            $process = new Process([$mysql, "--defaults-extra-file={$credentials}", '--default-character-set=utf8mb4', (string) $config['database']]);
            $process->setInput($input)->setTimeout(1800)->run();
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            @unlink($credentials);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Loading the MySQL dump failed: ' . trim(SafeLog::describe(new RuntimeException($process->getErrorOutput() ?: 'no output'))));
        }
        DB::purge($connection);
    }

    /**
     * Put the restored files in place. The current folder is renamed aside
     * first and deleted once the swap worked, unless $keepOld (no safety
     * backup holds it). Returns the folder kept aside, if any.
     */
    private function swapFiles(string $incoming, bool $keepOld, string $at): ?string
    {
        $live = $this->backups->filesPath();
        $aside = null;
        if (is_dir($live)) {
            $aside = "{$live}.replaced-{$at}";
            if (! @rename($live, $aside)) {
                throw new RuntimeException("Could not move the current files aside ({$live} is in use).");
            }
        }
        if (! @rename($incoming, $live)) {
            if ($aside !== null) {
                @rename($aside, $live);
            }
            throw new RuntimeException("Could not put the restored files in {$live}.");
        }
        if ($aside !== null && ! $keepOld) {
            File::deleteDirectory($aside);

            return null;
        }

        return $aside;
    }
}
