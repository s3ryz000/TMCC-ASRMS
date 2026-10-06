<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled jobs write to the system log (#62): the nightly backup runs with
 * no signed-in user, so system_logs.user_id becomes nullable and such rows
 * carry role "system". Existing rows are unchanged; row counts of every
 * table and PRAGMA foreign_key_check are compared before and after.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guarded(fn () => Schema::table('system_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        }));
    }

    public function down(): void
    {
        if (DB::table('system_logs')->whereNull('user_id')->exists()) {
            throw new RuntimeException('system_logs has rows without a user (system jobs); delete or reassign them before rolling back.');
        }

        $this->guarded(fn () => Schema::table('system_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        }));
    }

    private function guarded(Closure $change): void
    {
        $before = $this->snapshot();
        $change();
        $after = $this->snapshot();

        if ($after !== $before) {
            throw new RuntimeException('Changing system_logs.user_id changed row counts or broke a reference. Before: '
                . json_encode($before) . ' After: ' . json_encode($after));
        }
    }

    private function snapshot(): array
    {
        $counts = collect(Schema::getTableListing(Schema::getCurrentSchemaName(), false))
            ->sort()
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();

        if (DB::getDriverName() === 'sqlite') {
            $counts['__foreign_key_violations'] = count(DB::select('PRAGMA foreign_key_check'));
            $counts['__system_logs_foreign_keys'] = collect(DB::select('PRAGMA foreign_key_list(system_logs)'))
                ->map(fn ($fk) => "{$fk->from}->{$fk->table}.{$fk->to}")->sort()->values()->all();
        }

        return $counts;
    }
};
