<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Subject;
use App\Support\CurriculumRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The registrar's prerequisite editor (#27). Prerequisites live only in
 * curriculum_prerequisites and curriculum.prerequisite_logic (#17).
 *
 * A prerequisite must be placed in the same program at an earlier term, can't
 * be the subject itself, and can't close a loop (A needs B needs A, directly
 * or through a chain). Loops can't arise from earlier-term links alone, but an
 * entry moved later (#24) can leave a link that points forward, so the chain
 * is checked as well. Changes apply to future enrollments; existing
 * enrollments and grades are never re-validated (#28).
 */
class CurriculumPrerequisites
{
    public const LOGIC = ['AND', 'OR'];

    /** Terms in order: Y1S1 = 1, Y1S2 = 2, Y2S1 = 3 ... */
    public static function termIndex(int|string $yearLevel, int|string $semester): int
    {
        return ((int) $yearLevel - 1) * 2 + (int) $semester;
    }

    /**
     * Problems with making $subjectIds the prerequisites of $entry, keyed by
     * the position in $subjectIds (subject_ids.N); empty when valid.
     *
     * @param int[] $subjectIds
     * @return array<string, string>
     */
    public function problems(Curriculum $entry, array $subjectIds): array
    {
        $entry->loadMissing(['program', 'subject']);
        $placed = Curriculum::with('subject')->where('program_id', $entry->program_id)->get()->keyBy('subject_id');
        $graph = $this->programGraph($entry->program_id, $entry->id);
        $entryTerm = self::termIndex($entry->year_level, $entry->semester);
        $errors = [];

        foreach (array_values($subjectIds) as $i => $subjectId) {
            $key = "subject_ids.{$i}";
            $prereq = $placed[$subjectId] ?? null;

            if ((int) $subjectId === (int) $entry->subject_id) {
                $errors[$key] = "{$entry->subject->code} can't be its own prerequisite.";
            } elseif (! $prereq) {
                $code = Subject::find($subjectId)?->code ?? "Subject #{$subjectId}";
                $errors[$key] = "{$code} is not in {$entry->program->code}'s curriculum.";
            } elseif (self::termIndex($prereq->year_level, $prereq->semester) >= $entryTerm) {
                $errors[$key] = self::notEarlier($prereq->subject->code, $prereq->year_level, $prereq->semester, $entry->subject->code, $entry->year_level, $entry->semester);
            } elseif ($path = self::path($graph, (int) $subjectId, (int) $entry->subject_id)) {
                $codes = array_map(fn ($id) => $placed[$id]->subject->code ?? "#{$id}", $path);
                $errors[$key] = "{$prereq->subject->code} already depends on {$entry->subject->code} ("
                    . implode(' → ', array_reverse($codes)) . '), so this would make a loop.';
            }
        }

        return $errors;
    }

    /**
     * Replace the entry's prerequisites and logic, and clear the seeder's
     * unresolved prerequisites: a saved list is the registrar's fix (#20).
     *
     * @param int[] $subjectIds
     */
    public function save(Curriculum $entry, array $subjectIds, string $logic): void
    {
        DB::transaction(function () use ($entry, $subjectIds, $logic) {
            $entry->prerequisites()->sync(array_values(array_unique(array_map('intval', $subjectIds))));
            $entry->forceFill(['prerequisite_logic' => $logic, 'unresolved_prerequisites' => null])->save();
        });
    }

    /** "Curriculum: BSTM THC4 prerequisites set to GEC5 AND THC1" */
    public static function logMessage(string $programCode, string $code, Collection $prerequisiteCodes, string $logic): string
    {
        return "Curriculum: {$programCode} {$code} prerequisites "
            . ($prerequisiteCodes->isEmpty() ? 'cleared' : 'set to ' . $prerequisiteCodes->join(" {$logic} "));
    }

    public static function notEarlier(string $prereqCode, int|string $prereqYear, int|string $prereqSemester, string $code, int|string $year, int|string $semester): string
    {
        return "{$prereqCode} (" . CurriculumRules::termLabel($prereqYear, $prereqSemester) . ") must come before {$code} ("
            . CurriculumRules::termLabel($year, $semester) . ').';
    }

    /**
     * A chain of "requires" links from $from to $to, as subject ids
     * [$to, ..., $from] read backwards, or null when there is none.
     *
     * @param array<int, int[]> $graph subject id => subject ids it requires
     * @return int[]|null
     */
    public static function path(array $graph, int $from, int $to): ?array
    {
        $previous = [$from => null];
        $queue = [$from];

        while ($queue) {
            $current = array_shift($queue);
            if ($current === $to) {
                $path = [];
                for ($node = $to; $node !== null; $node = $previous[$node]) {
                    $path[] = $node;
                }

                return $path;
            }
            foreach ($graph[$current] ?? [] as $next) {
                if (! array_key_exists($next, $previous)) {
                    $previous[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * subject id => the subject ids it requires, for one program, leaving out
     * the links of the entry being edited (they are being replaced).
     *
     * @return array<int, int[]>
     */
    private function programGraph(int $programId, int $exceptEntryId): array
    {
        return DB::table('curriculum_prerequisites')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_prerequisites.curriculum_id')
            ->where('curriculum.program_id', $programId)
            ->where('curriculum.id', '!=', $exceptEntryId)
            ->get(['curriculum.subject_id', 'curriculum_prerequisites.prerequisite_subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->pluck('prerequisite_subject_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }
}
