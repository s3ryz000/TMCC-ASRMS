<?php

namespace App\Services\Enrollment\Rules;

use App\Models\Curriculum;
use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;
use Illuminate\Support\Collection;

/**
 * A program-completion subject (#82) — a curriculum row marked
 * requires_all_other_subjects, such as PRACTICUM in BSTM and BSHM — can be
 * enrolled only once every other subject in the student's program
 * curriculum is Passed or Credited.
 *
 * Two kinds of subject are not waited for, because waiting would block the
 * student for good: another program-completion subject (two of them would
 * each wait for the other), and an archived subject the student has not
 * passed (it can no longer be enrolled, #68).
 *
 * Like every rule it runs only when a new enrollment is made; existing
 * enrollments are never re-validated (#28).
 */
class ProgramCompletionRule implements EnrollmentRule
{
    /** Codes listed in the message; the rest are counted. */
    private const LISTED = 10;

    public function category(): string
    {
        return RuleCategory::PREREQUISITE;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        $entry = $context->curriculumFor($subjectId);

        if ($entry === null || ! $entry->requires_all_other_subjects) {
            return null;
        }

        $remaining = self::remaining($context->programCurriculum(), $subjectId, $context->passedSubjectIds);

        return $remaining === []
            ? null
            : self::message($context->codeFor($subjectId), $context->student->program?->code, $remaining);
    }

    /**
     * Codes of the other subjects still to pass, in curriculum order.
     *
     * @param Collection<int, Curriculum> $programCurriculum every entry of the program, with subject
     * @param int[] $passedSubjectIds
     * @return string[]
     */
    public static function remaining(Collection $programCurriculum, int $subjectId, array $passedSubjectIds): array
    {
        $passed = array_map('intval', $passedSubjectIds);

        return $programCurriculum
            ->filter(fn (Curriculum $other) => (int) $other->subject_id !== $subjectId
                && ! $other->requires_all_other_subjects
                && $other->subject !== null
                && ! in_array((int) $other->subject_id, $passed, true)
                && $other->subject->archived_at === null)
            ->sortBy([['year_level', 'asc'], ['semester', 'asc'], [fn ($e) => $e->subject->code, 'asc']])
            ->map(fn (Curriculum $other) => $other->subject->code)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "PRACTICUM can be taken after all other subjects in BSTM are passed
     * (3 remaining: TPC9, TPC10, THC10)."
     *
     * @param string[] $remaining
     */
    public static function message(string $code, ?string $programCode, array $remaining): string
    {
        $count = count($remaining);
        $listed = implode(', ', array_slice($remaining, 0, self::LISTED));
        if ($count > self::LISTED) {
            $listed .= ' and ' . ($count - self::LISTED) . ' more';
        }
        $program = $programCode ? "in {$programCode} " : 'in the program ';

        return "{$code} can be taken after all other subjects {$program}are passed ({$count} remaining: {$listed}).";
    }
}
