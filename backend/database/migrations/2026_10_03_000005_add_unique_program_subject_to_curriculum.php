<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A subject appears once per program curriculum (#24).
 *
 * The existing unique index covers (program, subject, year, semester), which
 * still lets one program place a subject in two terms. The curriculum entries
 * API refuses that with a message; this index makes the database refuse it
 * too. It only adds an index: no row is changed.
 *
 * It refuses to run, changing nothing, if a program already places a subject
 * twice, and lists those placements.
 */
return new class extends Migration
{
    private const INDEX = 'curriculum_program_id_subject_id_unique';

    public function up(): void
    {
        $duplicates = DB::table('curriculum')
            ->select('program_id', 'subject_id', DB::raw('COUNT(*) as placements'))
            ->groupBy('program_id', 'subject_id')
            ->having('placements', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('curriculum (program_id, subject_id) unique index not added; nothing was changed. '
                . 'Subjects placed more than once in a program: '
                . $duplicates->map(fn ($d) => "program {$d->program_id}, subject {$d->subject_id} ({$d->placements}x)")->join('; ') . '.');
        }

        Schema::table('curriculum', function (Blueprint $table) {
            $table->unique(['program_id', 'subject_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('curriculum', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
