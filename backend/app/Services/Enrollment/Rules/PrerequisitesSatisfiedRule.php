<?php

namespace App\Services\Enrollment\Rules;

use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;

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

        $missing = $entry->missingPrerequisites($context->passedSubjectIds);

        if ($missing->isEmpty()) {
            return null;
        }

        // OR reports every alternative; AND reports only the unmet ones.
        return $this->describe($missing->pluck('code')->all(), $entry->prerequisite_logic ?? 'AND')
            . " must be completed (Passed/Credited) before enrolling in {$code}.";
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
