<?php

namespace App\Services\Enrollment;

/**
 * One enrollment rule.
 *
 * Adding a rule to the system means adding a class that implements this
 * interface and registering it in EnrollmentValidator — no existing rule and
 * no controller has to change.
 */
interface EnrollmentRule
{
    /**
     * Which RuleCategory a violation of this rule belongs to. Callers use the
     * category to decide whether a violation is fatal or merely skips the
     * subject.
     */
    public function category(): string;

    /**
     * Check one subject against this rule.
     *
     * @return string|null Null when the rule is satisfied, otherwise a
     *                     human-readable explanation of the violation.
     */
    public function check(EnrollmentContext $context, int $subjectId): ?string;
}
