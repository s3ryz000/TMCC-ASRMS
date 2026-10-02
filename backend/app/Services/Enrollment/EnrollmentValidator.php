<?php

namespace App\Services\Enrollment;

use App\Models\Curriculum;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Enrollment\Rules\CurriculumMembershipRule;
use App\Services\Enrollment\Rules\NoActiveDuplicateRule;
use App\Services\Enrollment\Rules\NotAlreadyPassedRule;
use App\Services\Enrollment\Rules\PrerequisitesSatisfiedRule;
use App\Services\Enrollment\Rules\SubjectNotArchivedRule;

/**
 * Runs every enrollment rule over a batch of subjects.
 *
 * This is the single place the rules live. Every path that creates an
 * enrollment goes through here, so a rule change lands once instead of being
 * copied into each controller and drifting.
 */
class EnrollmentValidator
{
    /** @var EnrollmentRule[] */
    private array $rules;

    public function __construct(private AcademicRecordQuery $records)
    {
        // Ordered cheapest-and-most-fundamental first: there is no point
        // reporting a prerequisite problem for a subject that is not in the
        // student's curriculum to begin with.
        $this->rules = [
            new SubjectNotArchivedRule(),
            new CurriculumMembershipRule(),
            new NotAlreadyPassedRule(),
            new NoActiveDuplicateRule(),
            new PrerequisitesSatisfiedRule(),
        ];
    }

    /**
     * @param int[] $subjectIds
     */
    public function validate(
        Student $student,
        EnrollmentTerm $term,
        array $subjectIds,
        EnrollmentPolicy $policy,
    ): ValidationOutcome {
        $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));

        if (empty($subjectIds)) {
            return new ValidationOutcome(errors: ['No subjects were selected for enrollment.']);
        }

        $context = $this->buildContext($student, $term, $policy, $subjectIds);

        $valid = [];
        $errors = [];
        $skipped = [];
        $skipReasons = [];

        foreach ($subjectIds as $subjectId) {
            $violation = null;
            $violationCategory = null;

            foreach ($this->rules as $rule) {
                $message = $rule->check($context, $subjectId);
                if ($message !== null) {
                    $violation = $message;
                    $violationCategory = $rule->category();
                    break; // First violation is enough — report one clear reason.
                }
            }

            if ($violation === null) {
                $valid[] = $subjectId;
                continue;
            }

            if ($policy->isSkippable($violationCategory)) {
                $skipped[] = $subjectId;
                $skipReasons[] = $violation;
                continue;
            }

            $errors[] = $violation;
        }

        return new ValidationOutcome(
            validSubjectIds: $valid,
            errors: $errors,
            skippedSubjectIds: $skipped,
            skipReasons: $skipReasons,
        );
    }

    /**
     * @param int[] $subjectIds
     */
    private function buildContext(
        Student $student,
        EnrollmentTerm $term,
        EnrollmentPolicy $policy,
        array $subjectIds,
    ): EnrollmentContext {
        // Curriculum rows for the subjects under consideration, within this
        // student's program. Used for prerequisite lookups and to tell "wrong
        // program" apart from "wrong term".
        $entries = Curriculum::with(['subject', 'prerequisites'])
            ->where('program_id', $student->program_id)
            ->whereIn('subject_id', $subjectIds)
            ->get();

        // Subjects that are valid for the term being enrolled. A student in the
        // fifth-year extension is completing outstanding requirements, so any
        // remaining curriculum subject is fair game.
        $validQuery = Curriculum::where('program_id', $student->program_id);

        if (! $policy->allowAnyCurriculumTerm) {
            $validQuery->where('year_level', $term->yearLevel)
                ->where('semester', $term->semester);
        }

        return new EnrollmentContext(
            student: $student,
            term: $term,
            policy: $policy,
            passedSubjectIds: $this->records->passedSubjectIds($student),
            curriculumSubjectIds: $validQuery->pluck('subject_id')->map('intval')->all(),
            curriculumEntries: $entries,
            subjects: Subject::whereIn('id', $subjectIds)->get(),
        );
    }
}
