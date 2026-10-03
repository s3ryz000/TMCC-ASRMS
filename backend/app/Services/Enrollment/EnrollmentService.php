<?php

namespace App\Services\Enrollment;

use App\Support\AcademicStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place in the application that creates an enrollment.
 *
 * Persisting here rather than in each controller closes a gap that used to be
 * invisible: two of the three paths wrote an Enrollment row without the
 * matching Grade row, and every prerequisite check reads the grades table. A
 * subject enrolled through those paths was therefore invisible to prerequisite
 * logic until somebody happened to enter a grade for it by hand.
 */
class EnrollmentService
{
    public function __construct(private EnrollmentValidator $validator)
    {
    }

    /**
     * Validate a batch and persist whatever passes.
     *
     * Nothing is written when a fatal violation is present — the whole batch is
     * rejected so the caller never records a partially-valid term.
     *
     * @param int[] $subjectIds
     */
    public function enroll(
        Student $student,
        EnrollmentTerm $term,
        array $subjectIds,
        EnrollmentPolicy $policy,
        ?User $actor = null,
        bool $isRetake = false,
    ): EnrollmentResult {
        $outcome = $this->validator->validate($student, $term, $subjectIds, $policy);

        if (! $outcome->isValid()) {
            return new EnrollmentResult(outcome: $outcome, enrolledCount: 0);
        }

        if (! $outcome->hasEnrollableSubjects()) {
            return new EnrollmentResult(outcome: $outcome, enrolledCount: 0);
        }

        $enrolled = 0;

        DB::transaction(function () use ($student, $term, $outcome, $actor, $isRetake, &$enrolled) {
            $enrolled = $this->persistMany($student, $term, $outcome->validSubjectIds, $actor, $isRetake);
        });

        return new EnrollmentResult(outcome: $outcome, enrolledCount: $enrolled);
    }

    /**
     * Write already-validated subjects.
     *
     * Deliberately does not open its own transaction so a caller enrolling
     * regular and retake subjects in one term can wrap both in a single unit of
     * work. Callers are responsible for having validated the IDs first.
     *
     * @param int[] $subjectIds
     * @return int Number of enrollments written.
     */
    public function persistMany(
        Student $student,
        EnrollmentTerm $term,
        array $subjectIds,
        ?User $actor = null,
        bool $isRetake = false,
    ): int {
        $count = 0;

        foreach ($subjectIds as $subjectId) {
            $this->persistOne($student, $term, (int) $subjectId, $actor, $isRetake);
            $count++;
        }

        return $count;
    }

    /**
     * Write the enrollment, its grade placeholder and its audit trail together.
     */
    private function persistOne(
        Student $student,
        EnrollmentTerm $term,
        int $subjectId,
        ?User $actor,
        bool $isRetake,
    ): void {
        $enrollment = Enrollment::create([
            'student_id'    => $student->student_id,
            'subject_id'    => $subjectId,
            'academic_year' => $term->academicYear,
            'semester'      => $term->semester,
            'year_level'    => $term->yearLevel,
            'status'        => AcademicStatus::ENROLLED,
            'is_retake'     => $isRetake,
        ]);

        // firstOrCreate rather than create: the grades table is unique on
        // (student, subject, academic_year, semester), and a subject re-added
        // after its enrollment was archived would otherwise collide.
        $grade = Grade::firstOrCreate(
            [
                'student_id'    => $student->student_id,
                'subject_id'    => $subjectId,
                'academic_year' => $term->academicYear,
                'semester'      => $term->semester,
            ],
            [
                'enrollment_id' => $enrollment->id,
                'status'        => AcademicStatus::ENROLLED,
                'grade_value'   => null,
                'remarks'       => null,
            ],
        );

        // Re-point an existing placeholder at the new enrollment so the two
        // rows never drift apart.
        if ($grade->wasRecentlyCreated === false && $grade->enrollment_id !== $enrollment->id) {
            $grade->update(['enrollment_id' => $enrollment->id]);
        }

        EnrollmentAuditLog::create([
            'student_id'    => $student->student_id,
            'enrollment_id' => $enrollment->id,
            'subject_id'    => $subjectId,
            'academic_year' => $term->academicYear,
            'semester'      => $term->semester,
            'old_status'    => null,
            'new_status'    => AcademicStatus::ENROLLED,
            'changed_by'    => $actor?->id,
            'action'        => $isRetake ? 'retake_enrollment_created' : 'enrollment_created',
            'reason'        => $isRetake
                ? 'Retake of previously Failed/Withdrawn/FDA attempt.'
                : null,
            'had_grade'     => false,
            'user_role'     => $actor?->roles->first()?->name ?? $actor?->role ?? null,
        ]);
    }

}
