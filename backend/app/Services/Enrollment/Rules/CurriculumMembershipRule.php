<?php

namespace App\Services\Enrollment\Rules;

use App\Services\Enrollment\EnrollmentContext;
use App\Services\Enrollment\EnrollmentRule;
use App\Services\Enrollment\RuleCategory;

/**
 * The subject must belong to the student's program curriculum for the term
 * being enrolled.
 *
 * This is the rule that was entirely absent from student creation, which is how
 * a student could be enrolled in any subject in the catalogue.
 */
class CurriculumMembershipRule implements EnrollmentRule
{
    public function category(): string
    {
        return RuleCategory::CURRICULUM;
    }

    public function check(EnrollmentContext $context, int $subjectId): ?string
    {
        if (in_array($subjectId, $context->curriculumSubjectIds, true)) {
            return null;
        }

        $code = $context->codeFor($subjectId);

        // Distinguish "wrong program" from "wrong term" — they need different
        // corrective action from the registrar.
        if ($context->curriculumFor($subjectId) === null) {
            return "{$code} is not part of this student's program curriculum.";
        }

        return "{$code} does not belong to the curriculum for Year "
            . "{$context->term->yearLevel}, Semester {$context->term->semester}.";
    }
}
