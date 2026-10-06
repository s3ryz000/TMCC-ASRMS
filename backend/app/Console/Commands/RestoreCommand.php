<?php

namespace App\Console\Commands;

use App\Services\Backup\RestoreService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan asrms:restore <file> --force
 *
 * Restores a backup set (#64): verifies it, takes a pre-restore backup, puts
 * the site in maintenance mode, replaces the database and uploaded files,
 * runs the migrations, and prints the row counts before, in the backup and
 * after. Without --force it only checks the file and changes nothing.
 * See deploy/RESTORE.md.
 */
class RestoreCommand extends Command
{
    protected $signature = 'asrms:restore {file : The backup zip to restore} {--force : Really replace the current data}';

    protected $description = 'Restore the ASRMS database and uploaded files from a backup (takes a safety backup first)';

    public function handle(RestoreService $restore): int
    {
        $file = (string) $this->argument('file');

        if (! $this->option('force')) {
            try {
                $manifest = $restore->inspect($file);
            } catch (Throwable $e) {
                $this->error("Restore refused: {$e->getMessage()}");

                return self::FAILURE;
            }
            $this->line("{$file} is a valid backup made " . ($manifest['created_at'] ?? '?') . '.');
            $this->countsTable($restore->currentCounts(), $manifest['counts'], null);
            $this->error('Restore refused: this replaces ALL current data. Run again with --force to restore. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $report = $restore->reportTo(fn (string $line) => $this->line($line))->restore($file);
        } catch (Throwable $e) {
            $this->error("Restore FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->countsTable($report['before'], $report['manifest']['counts'], $report['after']);
        if ($report['kept']) {
            $this->warn("The files that were here before are kept in {$report['kept']}.");
        }
        $this->info(sprintf('Restore completed in %.1f s.', $report['seconds']));

        if ($report['after'] !== $report['manifest']['counts']) {
            $this->error('The row counts after the restore differ from the backup. Check before reopening the system.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function countsTable(?array $before, array $backup, ?array $after): void
    {
        $headers = ['Table', 'Before', 'In backup'];
        if ($after !== null) {
            $headers[] = 'After';
        }
        $rows = [];
        foreach ($backup as $table => $count) {
            $row = [$table, $before[$table] ?? '-', $count];
            if ($after !== null) {
                $row[] = $after[$table] ?? '-';
            }
            $rows[] = $row;
        }
        $this->table($headers, $rows);
    }
}
