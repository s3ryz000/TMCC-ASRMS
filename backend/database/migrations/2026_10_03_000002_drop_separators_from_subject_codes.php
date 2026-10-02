<?php

use App\Models\Subject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The registrar's code format (#16, 2 Oct 2026): prefix and number with
 * nothing in between, uppercase ("THC 3" -> "THC3", "PATHFit 1" -> "PATHFIT1"),
 * and the GE subjects renumbered (GEC-PC -> GEC4, GE ELECT 3 -> GEE7, ...).
 *
 * Only subjects.code changes. Rows are renamed by id, so curriculum,
 * prerequisites, enrollments and grades follow without being touched. Every
 * rename is recorded in subject_code_changes, which down() reads to put the
 * previous codes back. The GE ELECT prefix is retired (its two subjects are
 * now GEE7 and GEE8) but kept so down() can restore it.
 *
 * It refuses to run, changing nothing, if a mapped code is missing or holds a
 * different course, if a new code already belongs to another subject, or if
 * two subjects would end up with the same code. A fresh database has no
 * subjects yet; the seeders then create the new codes directly.
 */
return new class extends Migration
{
    /** Code now => [new code, title] — the registrar's list of 2 Oct 2026. */
    private const MAP = [
        'ACCTG 1'    => ['ACCTG1', 'Accounting, Business, and Management 1'],
        'ACCTG 2'    => ['ACCTG2', 'Accounting, Business, and Management 2'],
        'BME 1'      => ['BME1', 'Operations Management in Tourism and Hospitality Industry'],
        'BME 2'      => ['BME2', 'Strategic Management in Tourism and Hospitality'],
        'ELT 1'      => ['ELT1', 'Hospitality Management'],
        'ELT 2'      => ['ELT2', 'Events Management'],
        'ELT 3'      => ['ELT3', 'Managing a Service Enterprise'],
        'ELT 4'      => ['ELT4', 'Entrepreneurial Leadership in an Organization'],
        'ELT 5'      => ['ELT5', 'Entrepreneurial Marketing Strategies'],
        'ENT 1'      => ['ENT1', 'Entrepreneurial Behavior'],
        'ENT 2'      => ['ENT2', 'Microeconomics'],
        'ENT 3'      => ['ENT3', 'Opportunity Seeking'],
        'ENT 4'      => ['ENT4', 'Market Research and Consumer Behavior'],
        'ENT 5'      => ['ENT5', 'Social Entrepreneurship'],
        'ENT 6'      => ['ENT6', 'Innovation Management'],
        'ENT 7'      => ['ENT7', 'Pricing and Costing'],
        'ENT 8'      => ['ENT8', 'Financial Management (Financial Analysis for Decision Making)'],
        'ENT 9'      => ['ENT9', 'Business Plan Preparation'],
        'ENT 10'     => ['ENT10', 'Business Plan Implementation 1'],
        'ENT 11'     => ['ENT11', 'Business Plan Implementation 2'],
        'ENT 12'     => ['ENT12', 'International Business and Trade'],
        'ENT 13'     => ['ENT13', 'Programs and Policies on Enterprise Development'],
        'GE ELECT 3' => ['GEE7', 'PEACE Education'],
        'GE ELECT 4' => ['GEE8', 'Living in the IT Era'],
        'GEC-AA'     => ['GEC9', 'Art Appreciation'],
        'GEC-ETH'    => ['GEC1', 'Ethics'],
        'GEC-LWR'    => ['GEC2', 'Life and Works of Rizal'],
        'GEC-MMW'    => ['GEC3', 'Mathematics in the Modern World'],
        'GEC-PC'     => ['GEC4', 'Purposive Communication'],
        'GEC-RPH'    => ['GEC5', 'Readings in Philippine History'],
        'GEC-STS'    => ['GEC6', 'Science, Technology, and Society'],
        'GEC-TCW'    => ['GEC7', 'The Contemporary World'],
        'GEC-UTS'    => ['GEC8', 'Understanding the Self'],
        'GEE-AH'     => ['GEE1', 'Arts and Humanities'],
        'GEE-EM'     => ['GEE2', 'The Entrepreneurial Mind'],
        'GEE-ES'     => ['GEE3', 'Environmental Science'],
        'GEE-GS'     => ['GEE4', 'Gender and Society'],
        'GEE-IP'     => ['GEE5', 'Indigenous People'],
        'GEE-SSP'    => ['GEE6', 'Social Science and Philosophy'],
        'HMPE 1'     => ['HMPE1', 'Introduction to Transport Services'],
        'HMPE 2'     => ['HMPE2', 'Bar and Beverage Management with Laboratory'],
        'HMPE 3'     => ['HMPE3', 'Front Office Operation'],
        'HMPE 4'     => ['HMPE4', 'Housekeeping Operations'],
        'HMPE 5'     => ['HMPE5', 'Food and Beverage Service with Lab'],
        'HPC 1'      => ['HPC1', 'Kitchen Essentials & Basic Food Preparation'],
        'HPC 2'      => ['HPC2', 'Fundamentals in Lodging Operations'],
        'HPC 3'      => ['HPC3', 'Applied Business Tools and Technologies (PMS) with Lab'],
        'HPC 4'      => ['HPC4', 'Supply Chain Management in Hospitality Industry'],
        'HPC 5'      => ['HPC5', 'Foreign Language 1'],
        'HPC 6'      => ['HPC6', 'Fundamentals in Food Service Operations'],
        'HPC 7'      => ['HPC7', 'Introduction to MICE'],
        'HPC 8'      => ['HPC8', 'Foreign Language 2'],
        'HPC 9'      => ['HPC9', 'Ergonomics and Facilities Planning for the Hospitality Industry'],
        'HPC 10'     => ['HPC10', 'Research in Hospitality'],
        'HRM'        => ['HRM', 'Human Resources Management'],
        'HUM 1'      => ['HUM1', 'Philippine Popular Culture'],
        'LAW'        => ['LAW', 'Business Law and Taxation'],
        'MDA'        => ['MDA', 'Multimedia Development Application'],
        'MGT 1'      => ['MGT1', 'Principles of Management'],
        'MIS'        => ['MIS', 'Management Information System'],
        'MKG 1'      => ['MKG1', 'Principles of Marketing'],
        'MOE 1'      => ['MOE1', 'Microsoft Productivity Tool 1'],
        'MOE 2'      => ['MOE2', 'Microsoft Productivity Tool 2'],
        'NSTP 1'     => ['NSTP1', 'CWTS/LTS/ROTC 1'],
        'NSTP 2'     => ['NSTP2', 'CWTS/LTS/ROTC 2'],
        'OM'         => ['OM', 'Operations Management'],
        'PATHFit 1'  => ['PATHFIT1', 'Movement Competency Training'],
        'PATHFit 2'  => ['PATHFIT2', 'Exercise-Based Fitness Activities'],
        'PATHFit 3'  => ['PATHFIT3', 'Physical Activities towards Health and Fitness 3 (Badminton)'],
        'PATHFit 4'  => ['PATHFIT4', 'Physical Activities towards Health and Fitness 4 (Basketball)'],
        'PRACTICUM'  => ['PRACTICUM', 'Practicum in Tourism and Hospitality Industry'],
        'STRAMA'     => ['STRAMA', 'Strategic Management'],
        'THC 1'      => ['THC1', 'Macro Perspective of Tourism and Hospitality'],
        'THC 2'      => ['THC2', 'Risk Management as Applied to Safety, Security, and Sanitation'],
        'THC 3'      => ['THC3', 'Quality Service Management in Tourism and Hospitality'],
        'THC 4'      => ['THC4', 'Philippine Tourism, Geography, and Culture'],
        'THC 5'      => ['THC5', 'Micro Perspective of Tourism and Hospitality'],
        'THC 6'      => ['THC6', 'Professional Development and Applied Ethics'],
        'THC 7'      => ['THC7', 'Tourism and Hospitality Marketing'],
        'THC 8'      => ['THC8', 'Legal Aspects in Tourism and Hospitality'],
        'THC 9'      => ['THC9', 'Multicultural Diversity in the Workplace for the Tourism Professional'],
        'THC 10'     => ['THC10', 'Entrepreneurship in Tourism and Hospitality'],
        'TMPE 1'     => ['TMPE1', 'Recreation and Leisure Management'],
        'TPC 1'      => ['TPC1', 'Global Tourism, Geography, and Culture'],
        'TPC 2'      => ['TPC2', 'Tour and Travel Management'],
        'TPC 3'      => ['TPC3', 'Sustainable Tourism'],
        'TPC 4'      => ['TPC4', 'Tourism Policy Planning and Development'],
        'TPC 5'      => ['TPC5', 'Introduction to MICE'],
        'TPC 6'      => ['TPC6', 'Foreign Language 1'],
        'TPC 7'      => ['TPC7', 'Transportation Management'],
        'TPC 8'      => ['TPC8', 'Research in Tourism'],
        'TPC 9'      => ['TPC9', 'Foreign Language 2'],
        'TPC 10'     => ['TPC10', 'Applied Business Tools Technology in Tourism with Lab'],
    ];

    /** Tables whose row counts must not change. */
    private const GUARDED_TABLES = ['subjects', 'curriculum', 'curriculum_prerequisites', 'enrollments', 'grades', 'students'];

    private const RETIRED_PREFIX = 'GE ELECT';

    public function up(): void
    {
        $summary = DB::transaction(function () {
            $before = $this->counts();
            $renames = $this->plan();

            foreach ($renames as $rename) {
                DB::table('subjects')->where('id', $rename['id'])->update(['code' => $rename['new'], 'updated_at' => now()]);
                DB::table('subject_code_changes')->insert([
                    'subject_id'         => $rename['id'],
                    'old_code'           => $rename['old'],
                    'old_title'          => $rename['title'],
                    'new_code'           => $rename['new'],
                    'new_title'          => $rename['title'],
                    'merged_subject_ids' => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            DB::table('subject_code_prefixes')->where('prefix', self::RETIRED_PREFIX)->update(['active' => false, 'updated_at' => now()]);

            if ($this->counts() !== $before) {
                throw new RuntimeException('Subject code rename changed row counts; rolled back. Before: '
                    .json_encode($before).' After: '.json_encode($this->counts()));
            }

            return array_map(fn ($r) => "#{$r['id']} {$r['old']}  =>  {$r['new']}", $renames);
        });

        $this->report($summary);
    }

    /** Put back the codes this migration changed (newest first) and the GE ELECT prefix. */
    public function down(): void
    {
        DB::transaction(function () {
            $changes = DB::table('subject_code_changes')->orderByDesc('id')->get()
                ->filter(fn ($c) => $this->isOwnRename($c->old_code, $c->new_code));

            foreach ($changes as $change) {
                DB::table('subjects')->where('id', $change->subject_id)->where('code', $change->new_code)
                    ->update(['code' => $change->old_code, 'updated_at' => now()]);
                DB::table('subject_code_changes')->where('id', $change->id)->delete();
            }

            DB::table('subject_code_prefixes')->where('prefix', self::RETIRED_PREFIX)->update(['active' => true, 'updated_at' => now()]);
        });
    }

    /**
     * Every subject's new code. Mapped codes must exist and hold the course
     * the map names; any other subject gets the general rule. Throws, before
     * anything is written, on a missing code, a different course or a clash.
     *
     * @return list<array{id: int, old: string, new: string, title: string}> only the codes that change
     */
    private function plan(): array
    {
        $subjects = DB::table('subjects')->orderBy('id')->get(['id', 'code', 'title']);
        if ($subjects->isEmpty()) {
            return []; // fresh database: the seeders create the new codes
        }

        $problems = [];
        $byCode = $subjects->keyBy('code');

        foreach (self::MAP as $old => [$new, $title]) {
            $subject = $byCode[$old] ?? null;
            if (! $subject) {
                $problems[] = "{$old} ({$title}) is missing";
            } elseif (Subject::normalizeTitle($subject->title) !== Subject::normalizeTitle($title)) {
                $problems[] = "{$old} is \"{$subject->title}\", expected \"{$title}\"";
            }
        }

        $targets = $subjects->map(fn ($s) => [
            'id'    => (int) $s->id,
            'old'   => $s->code,
            'new'   => isset(self::MAP[$s->code]) ? self::MAP[$s->code][0] : Subject::formatCode($s->code),
            'title' => $s->title,
        ]);

        // Every subject is in the plan, so a clash is two subjects sharing a new code.
        foreach ($targets->groupBy(fn ($t) => mb_strtoupper($t['new'])) as $code => $group) {
            if ($group->count() > 1) {
                $problems[] = "{$code} would belong to ".$group->map(fn ($t) => "#{$t['id']} {$t['old']}")->join(' and ');
            }
        }

        // Renaming one row onto another's current code would trip the unique index mid-way.
        $currentHolder = $subjects->keyBy(fn ($s) => mb_strtoupper($s->code));
        foreach ($targets as $t) {
            $holder = $currentHolder[mb_strtoupper($t['new'])] ?? null;
            if ($holder && (int) $holder->id !== $t['id']) {
                $problems[] = "{$t['new']} (for {$t['old']}) already belongs to #{$holder->id} {$holder->title}";
            }
        }

        if ($problems) {
            throw new RuntimeException('Subject codes not renamed; nothing was changed: '.implode('; ', array_unique($problems)).'.');
        }

        return $targets->filter(fn ($t) => $t['new'] !== $t['old'])->values()->all();
    }

    /** A subject_code_changes row written by up(): a mapped rename or the general rule. */
    private function isOwnRename(string $old, string $new): bool
    {
        if (isset(self::MAP[$old])) {
            return self::MAP[$old][0] === $new && $old !== $new;
        }

        return $old !== $new && Subject::formatCode($old) === $new;
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

        fwrite(STDOUT, PHP_EOL.'  Subject codes reformatted ('.count($summary).' rows, recorded in subject_code_changes):'.PHP_EOL);
        foreach ($summary as $line) {
            fwrite(STDOUT, "    {$line}".PHP_EOL);
        }
        fwrite(STDOUT, PHP_EOL);
    }
};
