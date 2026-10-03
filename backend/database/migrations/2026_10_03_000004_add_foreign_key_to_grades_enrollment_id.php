<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every grade points at a real enrollment (#19).
 *
 * grades.enrollment_id had no foreign key, so a grade could point at an
 * enrollment that never existed or was hard-deleted. It now references
 * enrollments.id with ON DELETE RESTRICT, like the catalogue keys (#15):
 * history is never cascaded away, and enrollments are soft-deleted, not
 * removed. The column keeps its nullability.
 *
 * It refuses to run, changing nothing, if a grade already points at a
 * missing enrollment (soft-deleted enrollments still exist) and lists them.
 * On SQLite the table is rebuilt with every column, index and existing
 * foreign key (subject_id RESTRICT, student_id CASCADE); every table's row
 * count and PRAGMA foreign_key_check are compared before and after.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('grades')
            ->whereNotNull('enrollment_id')
            ->whereNotIn('enrollment_id', DB::table('enrollments')->select('id'))
            ->get(['id', 'student_id', 'subject_id', 'enrollment_id']);

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('grades.enrollment_id foreign key not added; nothing was changed. '
                . 'Grades pointing at a missing enrollment: '
                . $orphans->map(fn ($g) => "#{$g->id} (student {$g->student_id}, subject {$g->subject_id}, enrollment {$g->enrollment_id})")->join('; ') . '.');
        }

        $this->rebuild(function (Blueprint $table) {
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->rebuild(function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
        });
    }

    private function rebuild(Closure $change): void
    {
        $before = $this->snapshot();

        Schema::table('grades', $change);

        $after = $this->snapshot();
        if ($after !== $before) {
            throw new RuntimeException('Changing the grades foreign keys changed row counts or broke a reference. Before: '
                . json_encode($before) . ' After: ' . json_encode($after)
                . '. Restore database.before-phase2-data.sqlite.');
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
