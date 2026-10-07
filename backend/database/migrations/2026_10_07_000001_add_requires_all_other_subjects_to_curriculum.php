<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Program-completion subjects (#82): a curriculum row marked
 * requires_all_other_subjects can be enrolled only once every other subject
 * of the program is Passed or Credited. The BSTM and BSHM curricula say this
 * of PRACTICUM ("Finished all Academic Requirements"), which the seeder used
 * to skip, leaving PRACTICUM with no requirement at all.
 *
 * Adds the column (false for every row) and marks PRACTICUM in BSTM and
 * BSHM. Nothing else changes, and existing enrollments are never
 * re-validated (#28). Row counts of every table are compared before and
 * after.
 */
return new class extends Migration
{
    private const PROGRAMS = ['BSTM', 'BSHM'];
    private const SUBJECT = 'PRACTICUM';

    public function up(): void
    {
        $this->guarded(function () {
            Schema::table('curriculum', function (Blueprint $table) {
                $table->boolean('requires_all_other_subjects')->default(false)->after('prerequisite_logic');
            });

            $ids = DB::table('curriculum')
                ->join('subjects', 'subjects.id', '=', 'curriculum.subject_id')
                ->join('programs', 'programs.id', '=', 'curriculum.program_id')
                ->where('subjects.code', self::SUBJECT)
                ->whereIn('programs.code', self::PROGRAMS)
                ->pluck('curriculum.id');

            DB::table('curriculum')->whereIn('id', $ids)->update(['requires_all_other_subjects' => true]);
        });
    }

    public function down(): void
    {
        $this->guarded(fn () => Schema::table('curriculum', function (Blueprint $table) {
            $table->dropColumn('requires_all_other_subjects');
        }));
    }

    private function guarded(Closure $change): void
    {
        $before = $this->snapshot();
        $change();
        $after = $this->snapshot();

        if ($after !== $before) {
            throw new RuntimeException('Changing the curriculum table changed row counts. Before: '
                . json_encode($before) . ' After: ' . json_encode($after));
        }
    }

    private function snapshot(): array
    {
        return collect(Schema::getTableListing(Schema::getCurrentSchemaName(), false))
            ->sort()
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
};
