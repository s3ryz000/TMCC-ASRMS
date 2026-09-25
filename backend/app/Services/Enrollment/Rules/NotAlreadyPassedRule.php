<?php

namespace App\Services\Enrollment\Rules;

use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;

/**
 * A subject already passed or credited cannot be enrolled again.
 *
 * Retakes are handled separately and never reach this rule: a retake is only
 * offered for a Failed/Withdrawn/FDA attempt, which by definition is not passed.
 */
class NotAlreadyPassedRule implements EnrollmentRule
{
    public function category(): string
    {
        return RuleCategory::ALREADY_PASSED;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        if (! $context->hasPassed($subjectId)) {
            return null;
        }

        return $context->codeFor($subjectId) . ' has already been passed or credited.';
    }
}
