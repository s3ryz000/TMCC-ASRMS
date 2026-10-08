<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Return for revision (#88): profile updates gain the status
 * "revision_required" and a review_history JSON column that keeps every
 * decision and resubmission (the registrar's latest reason or remarks stay in
 * rejection_reason). Existing rows are unchanged; row counts of every table
 * and PRAGMA foreign_key_check are compared before and after.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guarded(fn () => Schema::table('pending_student_updates', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'revision_required'])->default('pending')->change();
            $table->json('review_history')->nullable();
        }));
    }

    public function down(): void
    {
        if (DB::table('pending_student_updates')->where('status', 'revision_required')->exists()) {
            throw new RuntimeException('Some profile updates are returned for revision; decide them before rolling back.');
        }

        $this->guarded(fn () => Schema::table('pending_student_updates', function (Blueprint $table) {
            $table->dropColumn('review_history');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        }));
    }

    private function guarded(Closure $change): void
    {
        $before = $this->snapshot();
        $change();
        $after = $this->snapshot();

        if ($after !== $before) {
            throw new RuntimeException('Changing pending_student_updates changed row counts or broke a reference. Before: '
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
            $counts['__pending_student_updates_foreign_keys'] = collect(DB::select('PRAGMA foreign_key_list(pending_student_updates)'))
                ->map(fn ($fk) => "{$fk->from}->{$fk->table}.{$fk->to}")->sort()->values()->all();
        }

        return $counts;
    }
};
