<?php

namespace App\Services\Enrollment;

/**
 * The result of validating a batch of subjects.
 */
class ValidationOutcome
{
    /**
     * @param int[]    $validSubjectIds Subjects that passed every rule.
     * @param string[] $errors          Fatal violations, ready to show the user.
     * @param int[]    $skippedSubjectIds Subjects dropped under a skippable category.
     * @param string[] $skipReasons     Parallel explanations for the skipped subjects.
     */
    public function __construct(
        public readonly array $validSubjectIds = [],
        public readonly array $errors = [],
        public readonly array $skippedSubjectIds = [],
        public readonly array $skipReasons = [],
    ) {
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function hasEnrollableSubjects(): bool
    {
        return ! empty($this->validSubjectIds);
    }
}
