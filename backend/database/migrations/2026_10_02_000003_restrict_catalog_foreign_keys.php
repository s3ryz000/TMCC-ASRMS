<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a subject or program must never erase student history (#15).
 *
 * These foreign keys cascaded (or nulled) on delete, so removing a subject
 * silently deleted its enrollments, grades, audit logs and curriculum
 * entries, and removing a program detached its students and blanked their
 * program history. They now RESTRICT: the database refuses the delete while
 * anything still refers to the row. Each column keeps its nullability.
 * Student-owned cascades (student_id) are unchanged.
 *
 * On SQLite each table is rebuilt; Laravel turns foreign keys off for the
 * rebuild (migrations here do not run inside a transaction), so the cascade
 * from students or curriculum to their children cannot fire. Every table's
 * row count is checked afterwards all the same.
 */
return new class extends Migration
{
    /** table => [column => [referenced table, on delete before this migration]] */
    private const KEYS = [
        'curriculum'               => ['program_id' => ['programs', 'cascade'], 'subject_id' => ['subjects', 'cascade']],
        'curriculum_prerequisites' => ['prerequisite_subject_id' => ['subjects', 'cascade']],
        'enrollments'              => ['subject_id' => ['subjects', 'cascade']],
        'grades'                   => ['subject_id' => ['subjects', 'cascade']],
        'enrollment_audit_logs'    => ['subject_id' => ['subjects', 'cascade']],
        'program_mappings'         => ['program_id' => ['programs', 'cascade']],
        'program_change_logs'      => ['old_program_id' => ['programs', 'set null'], 'new_program_id' => ['programs', 'cascade']],
        'students'                 => ['program_id' => ['programs', 'set null']],
    ];

    public function up(): void
    {
        $this->rebuild(fn () => 'restrict');
    }

    public function down(): void
    {
        $this->rebuild(fn (string $previous) => $previous);
    }

    /** Re-create every listed foreign key with the on-delete action $action returns. */
    private function rebuild(Closure $action): void
    {
        $before = $this->snapshot();

        foreach (self::KEYS as $table => $keys) {
            Schema::table($table, function (Blueprint $blueprint) use ($keys) {
                foreach (array_keys($keys) as $column) {
                    $blueprint->dropForeign([$column]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($keys, $action) {
                foreach ($keys as $column => [$references, $previous]) {
                    $blueprint->foreign($column)->references('id')->on($references)->onDelete($action($previous));
                }
            });
        }

        $after = $this->snapshot();
        if ($after !== $before) {
            throw new RuntimeException('Rebuilding catalogue foreign keys changed row counts. Before: '
                . json_encode($before) . ' After: ' . json_encode($after)
                . '. Restore database.before-phase1.sqlite.');
        }
    }

    /** Row counts of every table, plus foreign-key violations on SQLite. */
    private function snapshot(): array
    {
        $counts = collect(Schema::getTableListing(Schema::getCurrentSchemaName(), false))
            ->sort()
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();

        if (DB::getDriverName() === 'sqlite') {
            $counts['__foreign_key_violations'] = count(DB::select('PRAGMA foreign_key_check'));
        }

        return $counts;
    }
};
