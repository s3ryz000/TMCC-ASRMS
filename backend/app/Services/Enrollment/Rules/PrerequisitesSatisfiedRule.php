<?php

namespace App\Services\Enrollment\Rules;

use App\Models\Subject;
use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;
use Illuminate\Support\Collection;

/**
 * Every prerequisite declared for the subject must already be passed or
 * credited.
 *
 * There is no same-batch bypass: submitting a prerequisite and the subject that
 * depends on it in one request still fails, because the prerequisite has not
 * been *completed* at the time of enrollment.
 *
 * This is the behaviour the progression flow has always had. The manual staff
 * form previously carried its own copy that ignored OR groups and ignored
 * unresolved prerequisites entirely.
 */
class PrerequisitesSatisfiedRule implements EnrollmentRule
{
    public function category(): string
    {
        return RuleCategory::PREREQUISITE;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        $entry = $context->curriculumFor($subjectId);

        if ($entry === null) {
            // Not in this program's curriculum at all — CurriculumMembershipRule
            // reports that; there is nothing meaningful to check here.
            return null;
        }

        $code = $context->codeFor($subjectId);

        // A prerequisite the seeder could not map to a real subject leaves the
        // chain unverifiable, so the subject is blocked rather than treated as
        // having no prerequisites.
        $unresolved = $entry->unresolved_prerequisites ?? [];
        if (! empty($unresolved)) {
            $display = implode(', ', $unresolved);

            return "{$code} cannot be enrolled: unresolved prerequisite(s) '{$display}'. "
                . 'Registrar must verify curriculum mapping.';
        }

        $prereqSubjects = $this->resolvePrerequisites($entry);

        if ($prereqSubjects->isEmpty()) {
            return null;
        }

        $requiredIds = $prereqSubjects->pluck('id')->all();
        $logic = $entry->prerequisite_logic ?? 'AND';

        if ($logic === 'OR') {
            // Any one satisfied prerequisite clears the subject.
            $missingIds = array_intersect($requiredIds, $context->passedSubjectIds)
                ? []
                : $requiredIds;
        } else {
            $missingIds = array_diff($requiredIds, $context->passedSubjectIds);
        }

        if (empty($missingIds)) {
            return null;
        }

        $missingCodes = $prereqSubjects
            ->filter(fn ($subject) => in_array($subject->id, $missingIds, true))
            ->pluck('code')
            ->all();

        return $this->describe($missingCodes, $logic)
            . " must be completed (Passed/Credited) before enrolling in {$code}.";
    }

    /**
     * Prefer the many-to-many prerequisites; fall back to the deprecated single
     * `curriculum.prerequisite` column for any row not yet migrated.
     *
     * @return Collection<int, Subject>
     */
    private function resolvePrerequisites($entry): Collection
    {
        if ($entry->relationLoaded('prerequisites') && $entry->prerequisites->isNotEmpty()) {
            return $entry->prerequisites;
        }

        if (! $entry->relationLoaded('prerequisites')) {
            $entry->load('prerequisites');
            if ($entry->prerequisites->isNotEmpty()) {
                return $entry->prerequisites;
            }
        }

        $legacyId = $entry->getAttributes()['prerequisite'] ?? null;
        if (! $legacyId) {
            return collect();
        }

        $legacySubject = $entry->getRelationValue('prerequisite') ?? Subject::find($legacyId);

        return $legacySubject ? collect([$legacySubject]) : collect();
    }

    /**
     * "A", "A or B", "A and B and C" — matching the phrasing the registrar
     * already sees in the progression flow.
     *
     * @param string[] $codes
     */
    private function describe(array $codes, string $logic): string
    {
        if (count($codes) <= 1) {
            return implode('', $codes);
        }

        if ($logic === 'OR') {
            return implode(' or ', $codes);
        }

        $last = array_pop($codes);

        return implode(' and ', $codes) . ' and ' . $last;
    }
}
