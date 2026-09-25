<?php

namespace App\Services\Enrollment;

/**
 * What actually happened when a batch was submitted.
 */
class EnrollmentResult
{
    public function __construct(
        public readonly ValidationOutcome $outcome,
        public readonly int $enrolledCount = 0,
    ) {
    }

    public function failed(): bool
    {
        return ! $this->outcome->isValid();
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->outcome->errors;
    }

    /** @return int[] */
    public function skippedSubjectIds(): array
    {
        return $this->outcome->skippedSubjectIds;
    }
}
