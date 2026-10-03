<?php

namespace App\Services\Enrollment\Rules;

use App\Support\AcademicStatus;
use App\Models\Enrollment;
use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentPolicy;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;

/**
 * The student must not already hold an active enrollment for the subject.
 *
 * The scope is a policy decision, not a rule decision, so it comes from the
 * context: the guided flow checks the target term only (a later retake of the
 * same subject is legitimate), while the manual staff form checks every term so
 * the same subject cannot sit active in two places at once.
 */
class NoActiveDuplicateRule implements EnrollmentRule
{
    /** Statuses that no longer occupy an active seat. */
    private const CLOSED_STATUSES = AcademicStatus::CLOSED;

    public function category(): string
    {
        return RuleCategory::DUPLICATE;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        $query = Enrollment::where('student_id', $context->student->student_id)
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', self::CLOSED_STATUSES);

        if ($context->policy->duplicateScope === EnrollmentPolicy::SCOPE_SAME_TERM) {
            $query->where('academic_year', $context->term->academicYear)
                ->where('semester', $context->term->semester);
        }

        if (! $query->exists()) {
            return null;
        }

        $code = $context->codeFor($subjectId);

        if ($context->policy->duplicateScope === EnrollmentPolicy::SCOPE_SAME_TERM) {
            return "{$code} already has an active enrollment for A.Y. "
                . "{$context->term->academicYear}, Semester {$context->term->semester}.";
        }

        return "{$code} already has an active enrollment.";
    }
}
