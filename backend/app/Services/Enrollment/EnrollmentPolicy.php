<?php

namespace App\Services\Enrollment;

/**
 * How a caller wants rule violations treated.
 *
 * The *rules* are identical for every enrollment path — that is the point of
 * this package. What legitimately differs is the caller's policy: the manual
 * staff form skips subjects the student is already enrolled in rather than
 * failing the whole batch, while the progression flow rejects the batch. Making
 * that an explicit, named object keeps it from drifting back into three
 * divergent copies of the rules themselves.
 */
class EnrollmentPolicy
{
    public const SCOPE_SAME_TERM = 'same_term';
    public const SCOPE_ANY_TERM  = 'any_term';

    /**
     * @param string[] $skippableCategories Rule categories that cause a subject
     *                                      to be skipped instead of failing the batch.
     */
    public function __construct(
        public readonly string $duplicateScope = self::SCOPE_SAME_TERM,
        public readonly array $skippableCategories = [],
        public readonly bool $allowAnyCurriculumTerm = false,
    ) {
    }

    /**
     * Staff manually adding subjects from the Edit Student screen.
     *
     * Duplicates are looked for across every term (the same subject should not
     * sit active in two terms at once) and are skipped rather than fatal, which
     * preserves the "N enrolled, M skipped" response the UI already renders.
     */
    public static function manualEntry(): self
    {
        return new self(
            duplicateScope: self::SCOPE_ANY_TERM,
            skippableCategories: [
                RuleCategory::ALREADY_PASSED,
                RuleCategory::DUPLICATE,
            ],
        );
    }

    /**
     * The guided next-term flow. Every violation is fatal so the registrar sees
     * exactly why a selection was refused.
     */
    public static function guidedNextTerm(bool $allowAnyCurriculumTerm = false): self
    {
        return new self(
            duplicateScope: self::SCOPE_SAME_TERM,
            skippableCategories: [],
            allowAnyCurriculumTerm: $allowAnyCurriculumTerm,
        );
    }

    /**
     * Initial subjects chosen while creating a student record. The student has
     * no history yet, so every violation is a genuine data-entry error.
     */
    public static function newStudent(): self
    {
        return new self(
            duplicateScope: self::SCOPE_SAME_TERM,
            skippableCategories: [],
        );
    }

    /**
     * Re-checking an existing enrollment that is being moved to another term.
     *
     * Only the rules that stay meaningful for a correction are fatal: the
     * subject must still belong to the program's curriculum and its
     * prerequisites must still be satisfied. "Already passed" and "duplicate"
     * are expected when editing an existing record, so they never block.
     */
    public static function termCorrection(): self
    {
        return new self(
            duplicateScope: self::SCOPE_SAME_TERM,
            skippableCategories: [
                RuleCategory::ALREADY_PASSED,
                RuleCategory::DUPLICATE,
            ],
            allowAnyCurriculumTerm: true,
        );
    }

    public function isSkippable(string $category): bool
    {
        return in_array($category, $this->skippableCategories, true);
    }
}
