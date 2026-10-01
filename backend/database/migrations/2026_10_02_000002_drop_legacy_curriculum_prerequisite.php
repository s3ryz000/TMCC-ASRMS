<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * curriculum_prerequisites becomes the only place prerequisites live (#17).
 *
 * The single-prerequisite column curriculum.prerequisite predates the pivot
 * and was only read as a fallback. Any value still in it is copied into the
 * pivot first (existing pairs are skipped), so nothing is lost, then the
 * column and its foreign key are dropped.
 *
 * On SQLite dropping the column rebuilds the curriculum table; Laravel turns
 * foreign keys off for the rebuild so the cascade from curriculum to
 * curriculum_prerequisites cannot fire. Row counts are checked afterwards
 * all the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('curriculum', 'prerequisite')) {
            return;
        }

        DB::transaction(function () {
            $legacy = DB::table('curriculum')
                ->whereNotNull('prerequisite')
                ->whereIn('prerequisite', DB::table('subjects')->select('id'))
                ->get(['id', 'prerequisite']);

            foreach ($legacy as $row) {
                DB::table('curriculum_prerequisites')->insertOrIgnore([
                    'curriculum_id'           => $row->id,
                    'prerequisite_subject_id' => $row->prerequisite,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }
        });

        $before = $this->counts();

        Schema::table('curriculum', function (Blueprint $table) {
            $table->dropForeign(['prerequisite']);
        });
        Schema::table('curriculum', function (Blueprint $table) {
            $table->dropColumn('prerequisite');
        });

        if ($this->counts() !== $before) {
            throw new RuntimeException('Dropping curriculum.prerequisite changed row counts. Before: '
                . json_encode($before) . ' After: ' . json_encode($this->counts())
                . '. Restore database.before-phase1.sqlite.');
        }
    }

    /** The column comes back empty; the pivot keeps every prerequisite. */
    public function down(): void
    {
        if (Schema::hasColumn('curriculum', 'prerequisite')) {
            return;
        }

        Schema::table('curriculum', function (Blueprint $table) {
            $table->unsignedBigInteger('prerequisite')->nullable()->after('semester');
            $table->foreign('prerequisite')->references('id')->on('subjects')->nullOnDelete();
        });
    }

    private function counts(): array
    {
        return [
            'curriculum'               => DB::table('curriculum')->count(),
            'curriculum_prerequisites' => DB::table('curriculum_prerequisites')->count(),
            'by_program'               => DB::table('curriculum')->selectRaw('program_id, COUNT(*) as n')
                ->groupBy('program_id')->orderBy('program_id')->pluck('n', 'program_id')->map(fn ($n) => (int) $n)->all(),
        ];
    }
};
