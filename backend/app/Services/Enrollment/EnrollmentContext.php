<?php

namespace App\Services\Enrollment;

use App\Models\Curriculum;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * An immutable snapshot of everything the rules need for one validation run.
 *
 * Building it once per batch keeps each rule free of database access, which is
 * what makes the rules unit-testable in isolation.
 */
class EnrollmentContext
{
    /** @var array<int, Curriculum> keyed by subject_id */
    private array $curriculumBySubject;

    /** @var array<int, Subject> keyed by subject id */
    private array $subjects;

    /**
     * @param int[] $passedSubjectIds
     * @param int[] $curriculumSubjectIds Subjects valid for this term.
     * @param Collection<int, Curriculum> $curriculumEntries
     * @param Collection<int, Subject> $subjects
     * @param Collection<int, Curriculum>|null $programCurriculum Every entry of the
     *        program, loaded only when a program-completion subject (#82) is in the batch.
     */
    public function __construct(
        public readonly Student $student,
        public readonly EnrollmentTerm $term,
        public readonly EnrollmentPolicy $policy,
        public readonly array $passedSubjectIds,
        public readonly array $curriculumSubjectIds,
        Collection $curriculumEntries,
        Collection $subjects,
        private readonly ?Collection $programCurriculum = null,
    ) {
        $this->curriculumBySubject = $curriculumEntries->keyBy('subject_id')->all();
        $this->subjects = $subjects->keyBy('id')->all();
    }

    /**
     * Every curriculum entry of the student's program, with its subject.
     *
     * @return Collection<int, Curriculum>
     */
    public function programCurriculum(): Collection
    {
        return $this->programCurriculum ?? collect();
    }

    /**
     * The curriculum row for a subject within this student's program, or null
     * when the subject is not part of that program at all.
     */
    public function curriculumFor(int $subjectId): ?Curriculum
    {
        return $this->curriculumBySubject[$subjectId] ?? null;
    }

    /** The subject row, or null if it has since been removed. */
    public function subjectFor(int $subjectId): ?Subject
    {
        return $this->subjects[$subjectId] ?? null;
    }

    /**
     * Display code for a subject, falling back to the id so error messages are
     * never empty even if a subject row has since been removed.
     */
    public function codeFor(int $subjectId): string
    {
        return $this->subjects[$subjectId]->code ?? "Subject #{$subjectId}";
    }

    public function hasPassed(int $subjectId): bool
    {
        return in_array($subjectId, $this->passedSubjectIds, true);
    }
}
