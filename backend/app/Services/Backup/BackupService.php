<?php

namespace App\Services\Backup;

use App\Models\SystemLog;
use App\Models\SystemSetting;
use App\Support\SafeLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Backups of the ASRMS data (#62): the database and the uploaded files in one
 * zip with a manifest, kept 7 daily / 4 weekly / 12 monthly, plus copies taken
 * before every update or restore. The same service verifies a set for restore
 * (#64) and reports the last result to the admin (#65).
 *
 * Backup files hold personal data. They are written only under the configured
 * backup folder, and pruning only ever deletes asrms-*.zip files there.
 */
class BackupService
{
    public const LABELS = ['daily', 'pre-update', 'pre-restore'];

    public const FOLDERS = ['daily', 'weekly', 'monthly', 'pre-update', 'pre-restore'];

    /** The row counts recorded in every manifest and compared after a restore. */
    public const COUNTED_TABLES = ['students', 'enrollments', 'grades', 'users', 'record_requests'];

    public const SETTING_LAST_RUN = 'backup.last_run';

    public const SETTING_LAST_SUCCESS = 'backup.last_success';

    public function root(): string
    {
        return rtrim((string) config('asrms.backup.path'), '\\/');
    }

    public function filesPath(): string
    {
        return rtrim((string) config('asrms.backup.files'), '\\/');
    }

    /**
     * Take a backup and record the result. Never throws: a failure is logged,
     * written to system_logs and stored for the admin, and returned.
     */
    public function run(string $label = 'daily'): BackupResult
    {
        $started = microtime(true);
        $at = now();
        $tmp = null;

        try {
            if (! in_array($label, self::LABELS, true)) {
                throw new RuntimeException("Unknown backup label \"{$label}\".");
            }
            $this->guardLocation();

            $folder = $this->root() . DIRECTORY_SEPARATOR . $label;
            $this->ensureWritable($folder);
            $tmp = $this->root() . DIRECTORY_SEPARATOR . '.tmp-' . $at->format('YmdHis') . '-' . bin2hex(random_bytes(3));
            File::ensureDirectoryExists($tmp);

            $database = $this->dumpDatabase($tmp);
            $files = $this->collectFiles();
            $manifest = [
                'app' => 'TMCC ASRMS',
                'format' => 1,
                'label' => $label,
                'created_at' => $at->toIso8601String(),
                'commit' => $this->commitHash(),
                'driver' => DB::getDriverName(),
                'database' => [
                    'file' => basename($database),
                    'bytes' => filesize($database),
                    'sha256' => hash_file('sha256', $database),
                ],
                'files' => [
                    'count' => count($files),
                    'bytes' => array_sum(array_map(fn ($f) => $f['bytes'], $files)),
                    'sha256' => $this->filesChecksum($files),
                ],
                'counts' => $this->counts(),
            ];

            $zipPath = $this->uniquePath($folder, 'asrms-' . $at->format('Ymd-Hi'));
            $this->writeZip($tmp . DIRECTORY_SEPARATOR . 'set.zip', $database, $files, $manifest);
            if (! @rename($tmp . DIRECTORY_SEPARATOR . 'set.zip', $zipPath)) {
                throw new RuntimeException("Could not move the backup into {$folder}.");
            }

            $promoted = $label === 'daily' ? $this->promote($zipPath, $at) : [];
            $pruned = [];
            foreach (array_unique(array_merge([$label], array_keys($promoted))) as $dir) {
                $pruned = array_merge($pruned, $this->prune($this->root() . DIRECTORY_SEPARATOR . $dir, (int) config("asrms.backup.keep.{$dir}", 10)));
            }

            $result = BackupResult::success($label, $zipPath, filesize($zipPath), $manifest, microtime(true) - $started, array_values($promoted), $pruned);
        } catch (Throwable $e) {
            $result = BackupResult::failure($label, $this->reason($e), microtime(true) - $started);
        } finally {
            if ($tmp !== null && is_dir($tmp)) {
                File::deleteDirectory($tmp);
            }
        }

        $this->record($result, $at);

        return $result;
    }

    /**
     * Copy a Sunday backup to weekly/ and a 1st-of-the-month backup to monthly/.
     *
     * @return array<string, string> folder => copied file
     */
    public function promote(string $zipPath, CarbonInterface $at): array
    {
        $targets = [];
        if ($at->isSunday()) {
            $targets[] = 'weekly';
        }
        if ($at->day === 1) {
            $targets[] = 'monthly';
        }

        $copied = [];
        foreach ($targets as $folder) {
            $dir = $this->root() . DIRECTORY_SEPARATOR . $folder;
            File::ensureDirectoryExists($dir);
            $copy = $dir . DIRECTORY_SEPARATOR . basename($zipPath);
            if (! @copy($zipPath, $copy)) {
                throw new RuntimeException("Could not copy the backup into {$folder}/.");
            }
            $copied[$folder] = $copy;
        }

        return $copied;
    }

    /**
     * Keep the newest $keep backup sets in a folder and delete the rest. Only
     * asrms-*.zip files directly inside a folder of the backup root are touched.
     *
     * @return string[] deleted files
     */
    public function prune(string $folder, int $keep): array
    {
        $root = realpath($this->root());
        $real = realpath($folder);
        if ($root === false || $real === false || dirname($real) !== $root || ! in_array(basename($real), self::FOLDERS, true)) {
            return [];
        }

        $sets = glob($real . DIRECTORY_SEPARATOR . 'asrms-*.zip') ?: [];
        usort($sets, fn ($a, $b) => strcmp(basename($b), basename($a))); // newest first
        $deleted = [];
        foreach (array_slice($sets, max(0, $keep)) as $old) {
            if (@unlink($old)) {
                $deleted[] = $old;
            }
        }

        return $deleted;
    }

    /**
     * Open a backup set, check it is complete and that its contents match the
     * manifest's checksums. Throws before anything is changed if not.
     */
    public function verify(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException("No backup file at {$zipPath}.");
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The backup file is not a readable zip.');
        }

        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['app'] ?? null) !== 'TMCC ASRMS' || ! isset($manifest['database']['file'])) {
                throw new RuntimeException('The backup has no valid manifest.json.');
            }
            $database = $zip->getFromName('database/' . $manifest['database']['file']);
            if ($database === false || hash('sha256', $database) !== $manifest['database']['sha256']) {
                throw new RuntimeException('The database in the backup does not match its checksum.');
            }

            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_starts_with($name, 'files/') && ! str_ends_with($name, '/')) {
                    $content = $zip->getFromIndex($i);
                    $files[] = ['path' => substr($name, 6), 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
                }
            }
            if ($this->filesChecksum($files) !== $manifest['files']['sha256'] || count($files) !== $manifest['files']['count']) {
                throw new RuntimeException('The uploaded files in the backup do not match their checksum.');
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    /** Row counts of the tables every manifest records. */
    public function counts(): array
    {
        return collect(self::COUNTED_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /** The last run and the last success, for the admin's Backups card (#65). */
    public function lastRun(): ?array
    {
        return $this->decode(SystemSetting::getValue(self::SETTING_LAST_RUN));
    }

    public function lastSuccess(): ?array
    {
        return $this->decode(SystemSetting::getValue(self::SETTING_LAST_SUCCESS));
    }

    /** Number of backup sets per folder. */
    public function copies(): array
    {
        return collect(self::FOLDERS)->mapWithKeys(function ($folder) {
            $dir = $this->root() . DIRECTORY_SEPARATOR . $folder;

            return [$folder => is_dir($dir) ? count(glob($dir . DIRECTORY_SEPARATOR . 'asrms-*.zip') ?: []) : 0];
        })->all();
    }

    /** Write one line to backup.log, a system_logs row and the stored result. */
    public function record(BackupResult $result, CarbonInterface $at): void
    {
        $line = sprintf('[%s] %s %s', $at->format('Y-m-d H:i:s'), $result->ok ? 'OK' : 'FAILED', $result->summary());
        try {
            File::ensureDirectoryExists(dirname((string) config('asrms.backup.log')));
            File::append((string) config('asrms.backup.log'), $line . PHP_EOL);
        } catch (Throwable) {
            // The system log and the stored result below still record the run.
        }

        try {
            SystemLog::create([
                'action' => $result->ok ? "Backup completed: {$result->summary()}" : "Backup FAILED: {$result->summary()}",
                'user_id' => null,
                'role' => 'system',
            ]);
        } catch (Throwable) {
            // A database that can't be written is also why a backup fails; backup.log has the line.
        }

        $stored = $result->toArray() + ['at' => $at->toIso8601String()];
        try {
            SystemSetting::setValue(self::SETTING_LAST_RUN, $stored);
            if ($result->ok) {
                SystemSetting::setValue(self::SETTING_LAST_SUCCESS, $stored);
            }
        } catch (Throwable) {
            // As above.
        }
    }

    // ------------------------------------------------------------- internals

    /** A backup folder inside public/ or inside the files it backs up would be unsafe. */
    private function guardLocation(): void
    {
        $root = str_replace('\\', '/', strtolower($this->root()));
        $public = str_replace('\\', '/', strtolower(rtrim(public_path(), '\\/')));
        $files = str_replace('\\', '/', strtolower($this->filesPath()));

        if ($root === '' || str_starts_with($root . '/', $public . '/')) {
            throw new RuntimeException('ASRMS_BACKUP_PATH must not be inside public/.');
        }
        if ($files !== '' && str_starts_with($root . '/', $files . '/')) {
            throw new RuntimeException('ASRMS_BACKUP_PATH must not be inside the uploaded-files folder it backs up.');
        }
    }

    private function ensureWritable(string $folder): void
    {
        if (! is_dir($folder) && ! @mkdir($folder, 0770, true) && ! is_dir($folder)) {
            throw new RuntimeException("Backup folder {$folder} can't be created.");
        }
        $probe = $folder . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(3));
        if (@file_put_contents($probe, 'ok') === false) {
            throw new RuntimeException("Backup folder {$folder} is not writable.");
        }
        @unlink($probe);
    }

    /** A consistent copy of the database: VACUUM INTO for SQLite, mysqldump for MySQL. */
    private function dumpDatabase(string $tmp): string
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $target = $tmp . DIRECTORY_SEPARATOR . 'database.sqlite';
            DB::statement('VACUUM INTO ?', [$target]);
            if (! is_file($target) || filesize($target) === 0) {
                throw new RuntimeException('The SQLite copy was not created.');
            }

            return $target;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $target = $tmp . DIRECTORY_SEPARATOR . 'database.sql';
            $connection = config('database.connections.' . DB::getDefaultConnection());
            $mysqldump = (string) config('asrms.backup.mysqldump');
            if (! is_file($mysqldump)) {
                throw new RuntimeException("mysqldump not found at {$mysqldump} (set ASRMS_MYSQLDUMP_PATH).");
            }
            $credentials = $this->mysqlCredentialsFile($tmp, $connection);
            $process = new Process([
                $mysqldump, "--defaults-extra-file={$credentials}", '--single-transaction', '--routines',
                '--default-character-set=utf8mb4', "--result-file={$target}", (string) $connection['database'],
            ]);
            $process->setTimeout(1800)->run();
            @unlink($credentials);
            if (! $process->isSuccessful() || ! is_file($target) || filesize($target) === 0) {
                throw new RuntimeException('mysqldump failed: ' . trim(SafeLog::describe(new RuntimeException($process->getErrorOutput() ?: 'no output'))));
            }

            return $target;
        }

        throw new RuntimeException("Backups support SQLite and MySQL, not \"{$driver}\".");
    }

    /** Credentials go in a temporary option file, never on the command line. */
    public function mysqlCredentialsFile(string $dir, array $connection): string
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'client-' . bin2hex(random_bytes(4)) . '.cnf';
        $escape = fn ($v) => str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v);
        file_put_contents($file, "[client]\n"
            . 'user="' . $escape($connection['username'] ?? '') . "\"\n"
            . 'password="' . $escape($connection['password'] ?? '') . "\"\n"
            . 'host="' . $escape($connection['host'] ?? '127.0.0.1') . "\"\n"
            . 'port=' . (int) ($connection['port'] ?? 3306) . "\n");

        return $file;
    }

    /** @return list<array{path: string, full: string, bytes: int, sha256: string}> */
    private function collectFiles(): array
    {
        $base = $this->filesPath();
        if ($base === '' || ! is_dir($base)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($base, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($relative === '.gitignore') {
                continue;
            }
            $files[] = ['path' => $relative, 'full' => $file->getPathname(), 'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
        }

        return $files;
    }

    /** One checksum over every file's path and content hash, independent of order. */
    private function filesChecksum(array $files): string
    {
        $lines = array_map(fn ($f) => $f['path'] . ':' . $f['sha256'], $files);
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    private function writeZip(string $path, string $database, array $files, array $manifest): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup zip.');
        }
        $zip->addFile($database, 'database/' . basename($database));
        foreach ($files as $file) {
            $zip->addFile($file['full'], 'files/' . $file['path']);
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (! $zip->close()) {
            throw new RuntimeException('Could not finish writing the backup zip.');
        }
    }

    private function uniquePath(string $folder, string $name): string
    {
        $path = $folder . DIRECTORY_SEPARATOR . $name . '.zip';
        for ($n = 2; file_exists($path); $n++) {
            $path = $folder . DIRECTORY_SEPARATOR . "{$name}-{$n}.zip";
        }

        return $path;
    }

    private function commitHash(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
            $process->setTimeout(5)->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** A reason fit for the logs: no query values or personal data. */
    private function reason(Throwable $e): string
    {
        return $e instanceof RuntimeException && ! $e instanceof \Illuminate\Database\QueryException
            ? $e->getMessage()
            : SafeLog::describe($e);
    }

    private function decode(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
