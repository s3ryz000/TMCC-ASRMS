<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * php artisan asrms:backup [--label=daily|pre-update|pre-restore]
 *
 * The nightly backup (#62), run by Windows Task Scheduler at 6:00 PM
 * (deploy/windows/register-backup-task.ps1), and the backup the update and
 * restore procedures take first. Exits 1 on failure.
 */
class BackupCommand extends Command
{
    protected $signature = 'asrms:backup {--label=daily : daily, pre-update or pre-restore}';

    protected $description = 'Back up the ASRMS database and uploaded files (7 daily / 4 weekly / 12 monthly)';

    public function handle(BackupService $backups): int
    {
        $result = $backups->run((string) $this->option('label'));

        if (! $result->ok) {
            $this->error("Backup FAILED: {$result->message}");

            return self::FAILURE;
        }

        $this->info("Backup completed: {$result->file}");
        $this->line('Size: ' . number_format($result->bytes) . " bytes in " . round($result->seconds, 1) . ' s');
        foreach ($result->manifest['counts'] as $table => $count) {
            $this->line(sprintf('  %-16s %d', $table, $count));
        }
        foreach ($result->promoted as $copy) {
            $this->line("Kept as: {$copy}");
        }
        if ($result->pruned) {
            $this->line('Removed ' . count($result->pruned) . ' older backup(s).');
        }

        return self::SUCCESS;
    }
}
