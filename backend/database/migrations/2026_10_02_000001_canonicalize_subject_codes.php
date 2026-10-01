<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One subject row, with one unique code, per course (#16).
 *
 * 2026_05_22_000001 swapped UNIQUE(code) for UNIQUE(code, title) because the
 * CHED curriculum documents number the same GE courses differently per
 * program (Understanding the Self is GE 1 in BSE but GE 4 in BSTM/BSHM). The
 * result was several rows per course and several courses per code. This
 * migration:
 *
 *   1. merges every group below into its lowest-id row (the survivor),
 *      re-pointing curriculum, prerequisites, enrollments, grades and
 *      enrollment audit logs from the duplicates to it, then deleting the
 *      now-unreferenced duplicates;
 *   2. gives each survivor (and each single-row rename) its canonical code
 *      and title;
 *   3. restores UNIQUE(code).
 *
 * It refuses to run, changing nothing, if a merge would collide on a unique
 * key (e.g. one student graded in two duplicates in the same term), if units
 * differ inside a group, or if a target code is held by an unrelated subject.
 *
 * Every rename is recorded in subject_code_changes, which down() reads to put
 * the original codes and titles back. system_logs needs a user, so it cannot
 * hold this record.
 */
return new class extends Migration
{
    /**
     * [target code, target title, [[existing code, existing title], ...]].
     * Codes proposed by the team on 2 Oct 2026; to be confirmed with the Registrar.
     */
    private const GROUPS = [
        // GE core: one row per course, shared by every program.
        ['GEC-UTS', 'Understanding the Self', [['GE 1', 'Understanding the Self'], ['GE 4', 'Understanding the Self']]],
        ['GEC-RPH', 'Readings in Philippine History', [['GE 2', 'Readings in Philippine History']]],
        ['GEC-TCW', 'The Contemporary World', [['GE 3', 'The Contemporary World'], ['GE 7', 'The Contemporary World'], ['GE 8', 'The Contemporary World']]],
        ['GEC-MMW', 'Mathematics in the Modern World', [['GE 4', 'Mathematics in the Modern World'], ['GE 3', 'Mathematics in the Modern World']]],
        ['GEC-PC', 'Purposive Communication', [['GE 5', 'Purposive Communication'], ['GE 1', 'Purposive Communication']]],
        ['GEC-AA', 'Art Appreciation', [['GE 6', 'Art Appreciation'], ['GE 8', 'Art Appreciation'], ['GE 9', 'Art Appreciation']]],
        ['GEC-STS', 'Science, Technology, and Society', [['GE 7', 'Science, Technology, and Society'], ['GE 5', 'Science, Technology, and Society'], ['GE 6', 'Science, Technology, and Society']]],
        ['GEC-ETH', 'Ethics', [['GE 8', 'Ethics'], ['GE 6', 'Ethics'], ['GE 7', 'Ethics']]],
        ['GEC-LWR', 'Life and Works of Rizal', [['GE 9', 'Life and Works of Rizal'], ['RIZAL', 'Life and Works of Rizal']]],
        ['GEE-IP', 'Indigenous People', [['GE 5', 'Indigenous People']]],

        // GE electives that shared a code.
        ['GEE-GS', 'Gender and Society', [['GE ELECT 1', 'Gender and Society']]],
        ['GEE-SSP', 'Social Science and Philosophy', [['GE ELECT 1', 'Social Science and Philosophy']]],
        ['GEE-ES', 'Environmental Science', [['GE ELECT 2', 'Environmental Science']]],
        ['GEE-AH', 'Arts and Humanities', [['GE ELECT 2', 'Arts and Humanities']]],
        ['GEE-EM', 'The Entrepreneurial Mind', [['GE ELECT 5', 'Entrepreneurial Mind'], ['GE ELECT 5', 'The Entrepreneurial Mind']]],

        // Program subjects: the same course spelled two ways.
        ['BME 1', 'Operations Management in Tourism and Hospitality Industry', [['BME 1', 'Operations Management in TH Industry'], ['BME 1', 'Operations Management in Tourism and Hospitality Industry']]],
        ['BME 2', 'Strategic Management in Tourism and Hospitality', [['BME 2', 'Strategic Management in Tourism and Hospitality 1'], ['BME 2', 'Strategic Management in Tourism and Hospitality']]],
        ['HMPE 2', 'Bar and Beverage Management with Laboratory', [['HMPE 2', 'Bar and Beverage Management with Lab'], ['HMPE 2', 'Bar and Beverage Management with Laboratory']]],
        ['HMPE 4', 'Housekeeping Operations', [['HMPE 4', 'Housekeeping Operation'], ['HMPE 4', 'Housekeeping Operations']]],
        ['THC 3', 'Quality Service Management in Tourism and Hospitality', [['THC 3', 'Tourism and Hospitality Service Quality Management'], ['THC 3', 'Quality Service Management in Tourism and Hospitality']]],
        ['THC 5', 'Micro Perspective of Tourism and Hospitality', [['THC 5', 'Tourism and Hospitality 2 (Micro Perspective of Tourism and Hospitality)'], ['THC 5', 'Micro Perspective of Tourism and Hospitality']]],
        ['THC 9', 'Multicultural Diversity in the Workplace for the Tourism Professional', [['THC 9', 'Multicultural Diversity in Workplace for the Tourism Professional'], ['THC 9', 'Multicultural Diversity in the Workplace for the Tourism Professional']]],

        // Genuinely different courses that shared a code: BSTM's moves to TMPE 1,
        // BSHM's HMPE 1 Introduction to Transport Services keeps its code.
        ['TMPE 1', 'Recreation and Leisure Management', [['HMPE 1', 'Recreation and Leisure Management']]],
    ];

    /** Tables whose row counts must not change while merging. */
    private const GUARDED_TABLES = ['curriculum', 'curriculum_prerequisites', 'enrollments', 'grades', 'enrollment_audit_logs'];

    public function up(): void
    {
        Schema::create('subject_code_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subject_id');
            $table->string('old_code', 20);
            $table->string('old_title', 150);
            $table->string('new_code', 20);
            $table->string('new_title', 150);
            $table->text('merged_subject_ids')->nullable(); // JSON list of deleted duplicate ids
            $table->timestamps();
        });

        try {
            $summary = DB::transaction(function () {
                $before = $this->counts();
                $plan = $this->plan();

                foreach ($plan as $step) {
                    $this->assertNoCollisions($step);
                }

                $summary = [];
                foreach ($plan as $step) {
                    $summary[] = $this->apply($step);
                }

                if ($this->counts() !== $before) {
                    throw new RuntimeException('Subject merge changed row counts; rolled back. Before: '
                        . json_encode($before) . ' After: ' . json_encode($this->counts()));
                }

                return array_filter($summary);
            });
        } catch (Throwable $e) {
            // The data changes rolled back; leave the schema as we found it too.
            Schema::dropIfExists('subject_code_changes');
            throw $e;
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique('subjects_code_title_unique');
            $table->unique('code');
        });

        $this->report($summary);
    }

    /**
     * Merged rows cannot be un-merged: their curriculum entries, grades and
     * enrollments stay on the survivor. Every surviving or renamed row gets
     * its original code and title back, and UNIQUE(code, title) returns.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['code', 'title'], 'subjects_code_title_unique');
        });

        DB::transaction(function () {
            foreach (DB::table('subject_code_changes')->orderByDesc('id')->get() as $change) {
                DB::table('subjects')->where('id', $change->subject_id)->update([
                    'code'       => $change->old_code,
                    'title'      => $change->old_title,
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::dropIfExists('subject_code_changes');
    }

    /**
     * Resolve every group to concrete rows. Groups with no matching rows are
     * skipped (a fresh database has no subjects yet).
     *
     * @return list<array{code: string, title: string, survivor: object, duplicates: list<int>}>
     */
    private function plan(): array
    {
        $plan = [];

        foreach (self::GROUPS as [$code, $title, $sources]) {
            $rows = DB::table('subjects')
                ->where(function ($q) use ($sources) {
                    foreach ($sources as [$sourceCode, $sourceTitle]) {
                        $q->orWhere(fn ($w) => $w->where('code', $sourceCode)->where('title', $sourceTitle));
                    }
                })
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            if ($rows->pluck('units')->map(fn ($u) => (int) $u)->unique()->count() > 1) {
                throw new RuntimeException("Cannot merge {$code}: the rows have different units ("
                    . $rows->map(fn ($r) => "#{$r->id} {$r->code} {$r->title} = {$r->units}")->join('; ') . ').');
            }

            $holder = DB::table('subjects')->where('code', $code)->whereNotIn('id', $rows->pluck('id'))->first();
            if ($holder) {
                throw new RuntimeException("Cannot use {$code} for {$title}: it already belongs to #{$holder->id} {$holder->title}.");
            }

            $plan[] = [
                'code'       => $code,
                'title'      => $title,
                'survivor'   => $rows->first(),
                'duplicates' => $rows->slice(1)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        return $plan;
    }

    /** Refuse to merge if re-pointing would break a unique key or create a self-prerequisite. */
    private function assertNoCollisions(array $step): void
    {
        if (empty($step['duplicates'])) {
            return;
        }

        $ids = array_merge([(int) $step['survivor']->id], $step['duplicates']);
        $problems = [];

        $checks = [
            'grades in the same term'                  => DB::table('grades')->whereIn('subject_id', $ids)->groupBy('student_id', 'academic_year', 'semester'),
            'live enrollments in the same term'        => DB::table('enrollments')->whereIn('subject_id', $ids)->whereNull('deleted_at')->groupBy('student_id', 'academic_year', 'semester'),
            'curriculum entries in the same program'   => DB::table('curriculum')->whereIn('subject_id', $ids)->groupBy('program_id'),
        ];
        foreach ($checks as $label => $query) {
            $hits = $query->havingRaw('COUNT(DISTINCT subject_id) > 1')->selectRaw('COUNT(*) as n')->get();
            if ($hits->isNotEmpty()) {
                $problems[] = "{$hits->count()} {$label}";
            }
        }

        $prereqHits = DB::table('curriculum_prerequisites')
            ->whereIn('prerequisite_subject_id', $ids)
            ->groupBy('curriculum_id')
            ->havingRaw('COUNT(DISTINCT prerequisite_subject_id) > 1')
            ->selectRaw('curriculum_id')
            ->get();
        if ($prereqHits->isNotEmpty()) {
            $problems[] = "{$prereqHits->count()} curriculum entries listing two of these as prerequisites";
        }

        $selfPrereq = DB::table('curriculum_prerequisites')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_prerequisites.curriculum_id')
            ->whereIn('curriculum.subject_id', $ids)
            ->whereIn('curriculum_prerequisites.prerequisite_subject_id', $ids)
            ->count();
        if ($selfPrereq > 0) {
            $problems[] = "{$selfPrereq} prerequisites that would become self-prerequisites";
        }

        if ($problems) {
            throw new RuntimeException("Cannot merge into {$step['code']} {$step['title']} (subject ids "
                . implode(', ', $ids) . '): ' . implode('; ', $problems) . '. Nothing was changed.');
        }
    }

    /** Re-point, delete duplicates, rename the survivor. Returns a summary line, or null if nothing changed. */
    private function apply(array $step): ?string
    {
        $survivor = $step['survivor'];
        $duplicates = $step['duplicates'];

        if ($duplicates) {
            $references = [
                ['curriculum', 'subject_id'],
                ['curriculum_prerequisites', 'prerequisite_subject_id'],
                ['enrollments', 'subject_id'],
                ['grades', 'subject_id'],
                ['enrollment_audit_logs', 'subject_id'],
            ];
            if (Schema::hasColumn('curriculum', 'prerequisite')) {
                $references[] = ['curriculum', 'prerequisite'];
            }

            foreach ($references as [$table, $column]) {
                DB::table($table)->whereIn($column, $duplicates)->update([$column => $survivor->id]);
            }

            foreach ($references as [$table, $column]) {
                if (DB::table($table)->whereIn($column, $duplicates)->exists()) {
                    throw new RuntimeException("{$table}.{$column} still references a merged duplicate of {$step['code']}.");
                }
            }

            DB::table('subjects')->whereIn('id', $duplicates)->delete();
        }

        if ($survivor->code === $step['code'] && $survivor->title === $step['title'] && ! $duplicates) {
            return null;
        }

        DB::table('subjects')->where('id', $survivor->id)->update([
            'code'       => $step['code'],
            'title'      => $step['title'],
            'updated_at' => now(),
        ]);

        DB::table('subject_code_changes')->insert([
            'subject_id'         => $survivor->id,
            'old_code'           => $survivor->code,
            'old_title'          => $survivor->title,
            'new_code'           => $step['code'],
            'new_title'          => $step['title'],
            'merged_subject_ids' => $duplicates ? json_encode($duplicates) : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $line = "#{$survivor->id} {$survivor->code} — {$survivor->title}  =>  {$step['code']} — {$step['title']}";

        return $duplicates ? $line . '  (merged #' . implode(', #', $duplicates) . ')' : $line;
    }

    private function counts(): array
    {
        return collect(self::GUARDED_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function report(array $summary): void
    {
        if (! $summary || app()->runningUnitTests() || ! defined('STDOUT')) {
            return;
        }

        fwrite(STDOUT, PHP_EOL . '  Subject codes canonicalised (' . count($summary) . ' rows, recorded in subject_code_changes):' . PHP_EOL);
        foreach ($summary as $line) {
            fwrite(STDOUT, "    {$line}" . PHP_EOL);
        }
        fwrite(STDOUT, PHP_EOL);
    }
};
