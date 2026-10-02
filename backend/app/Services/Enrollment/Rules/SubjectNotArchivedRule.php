<?php

namespace App\Services\Enrollment\Rules;

use App\Models\Subject;
use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;

/**
 * An archived subject cannot be added to a new enrollment (#68).
 *
 * Archiving retires a subject that can no longer be deleted (#15). Records
 * that already contain it never change: this rule only runs when an
 * enrollment is being created, and EnrollmentPolicy::termCorrection() skips
 * it when an existing enrollment is merely moved to another term.
 *
 * Retakes are validated by RetakeEligibilityService rather than by
 * EnrollmentValidator, so it calls violationFor() directly.
 */
class SubjectNotArchivedRule implements EnrollmentRule
{
    public function category(): string
    {
        return RuleCategory::ARCHIVED;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        return self::violationFor($context->subjectFor($subjectId));
    }

    /** The refusal message for an archived subject, or null when it may be enrolled. */
    public static function violationFor(?Subject $subject): ?string
    {
        if ($subject === null || $subject->archived_at === null) {
            return null;
        }

        return "{$subject->code} {$subject->title} is archived and can't be added to new enrollments.";
    }
}
