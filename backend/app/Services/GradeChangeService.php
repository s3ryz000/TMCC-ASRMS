<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\User;
use App\Support\AcademicStatus;

/**
 * The one place a grade's value, status and remarks change. problem() runs
 * every rule without saving, so grade entry can refuse a bad row when it is
 * submitted for approval (#107); apply() runs the same rules and saves when
 * the submission is approved.
 */
class GradeChangeService
{
    public function __construct(private AcademicProgressionService $progression)
    {
    }

    /**
     * What the change would save: the rounded value, the derived status and
     * remarks, and the conversion stamp (INC or Conditional resolved).
     *
     * @param array{grade_value?: mixed, status?: ?string, remarks?: ?string, supporting_document_reference?: ?string} $data
     * @return array{grade_value: ?float, status: string, remarks: ?string, converted_from: ?string}
     */
    public function resolve(Grade $grade, array $data): array
    {
        $gradeValue = isset($data['grade_value']) && $data['grade_value'] !== ''
            ? round((float) $data['grade_value'], 2)
            : null;

        $newStatus = $this->progression->autoStatusFromGrade($gradeValue, $data['status'] ?? null);

        $remarks = ! empty($data['remarks'])
            ? $data['remarks']
            : $this->progression->autoGenerateRemarks($gradeValue, $newStatus);

        $convertedFrom = null;
        if ($grade->status === AcademicStatus::INC && $newStatus === AcademicStatus::PASSED) {
            $convertedFrom = AcademicStatus::INC;
        } elseif ($grade->status === AcademicStatus::CONDITIONAL && $newStatus !== AcademicStatus::CONDITIONAL) {
            $convertedFrom = AcademicStatus::CONDITIONAL;
        }

        return ['grade_value' => $gradeValue, 'status' => $newStatus, 'remarks' => $remarks, 'converted_from' => $convertedFrom];
    }

    /**
     * Why the change can't be saved, or null when it can. Nothing is written.
     *
     * @param array{grade_value?: mixed, status?: ?string, remarks?: ?string, supporting_document_reference?: ?string} $data
     */
    public function problem(Grade $grade, array $data): ?string
    {
        $new = $this->resolve($grade, $data);

        if ($new['status'] === AcademicStatus::CREDITED && empty($data['supporting_document_reference'])) {
            return "Grade #{$grade->id}: Credited status requires a supporting document reference.";
        }

        // TMCC: a 4.0 (Conditional) can only become 3.0 or 5.0 (#104).
        if ($grade->status === AcademicStatus::CONDITIONAL && $new['status'] !== AcademicStatus::CONDITIONAL) {
            $value = $new['grade_value'];
            $resolved = ($new['status'] === AcademicStatus::PASSED && $value !== null && abs($value - 3.00) < 0.001)
                || ($new['status'] === AcademicStatus::FAILED && $value !== null && abs($value - 5.00) < 0.001);
            if (! $resolved) {
                return "Grade #{$grade->id}: a Conditional (4.0) grade can only be resolved to 3.00 (Passed) or 5.00 (Failed).";
            }
        }

        return null;
    }

    /**
     * Apply one grade change the way every grade write must: the checks in
     * problem(), then save, keep the enrollment row in step, and write the
     * audit trail. Callers own the surrounding transaction.
     *
     * @param array{grade_value?: mixed, status?: ?string, remarks?: ?string, supporting_document_reference?: ?string} $data
     * @return string|null An error message, or null once the change is saved.
     */
    public function apply(Grade $grade, array $data, User $user, ?string $role): ?string
    {
        if ($problem = $this->problem($grade, $data)) {
            return $problem;
        }

        $new = $this->resolve($grade, $data);
        $gradeValue = $new['grade_value'];
        $newStatus = $new['status'];
        $remarks = $new['remarks'];
        $convertedFrom = $new['converted_from'];

        // Captured before the update: once saved, Eloquent re-syncs the
        // "original" attributes, so reading them afterwards returns the new
        // values and every audit row would show old status = new status.
        $oldStatus = $grade->status;
        $oldValue = json_encode([
            'grade_value' => $grade->grade_value,
            'status'      => $grade->status,
            'remarks'     => $grade->remarks,
        ]);

        $grade->update([
            'grade_value'                   => $gradeValue,
            'status'                        => $newStatus,
            'remarks'                       => $remarks,
            'supporting_document_reference' => $data['supporting_document_reference'] ?? $grade->supporting_document_reference,
            'converted_from_status'         => $convertedFrom ?? $grade->converted_from_status,
            'converted_at'                  => $convertedFrom ? now() : $grade->converted_at,
        ]);

        if ($grade->enrollment_id) {
            Enrollment::where('id', $grade->enrollment_id)->update(['status' => $newStatus]);
        } else {
            Enrollment::where('student_id', $grade->student_id)
                ->where('subject_id', $grade->subject_id)
                ->where('academic_year', $grade->academic_year)
                ->where('semester', $grade->semester)
                ->whereNull('deleted_at')
                ->update(['status' => $newStatus]);
        }

        $action = 'grade_updated';
        if ($convertedFrom === AcademicStatus::INC) {
            $action = 'inc_to_passed';
        } elseif ($convertedFrom === AcademicStatus::CONDITIONAL) {
            $action = 'conditional_resolved';
        } elseif ($newStatus === AcademicStatus::CREDITED) {
            $action = 'marked_credited';
        }

        EnrollmentAuditLog::create([
            'student_id'                    => $grade->student_id,
            'enrollment_id'                 => $grade->enrollment_id ?? 0,
            'subject_id'                    => $grade->subject_id,
            'academic_year'                 => $grade->academic_year,
            'semester'                      => $grade->semester,
            'old_status'                    => $oldStatus,
            'new_status'                    => $newStatus,
            'changed_by'                    => $user->id,
            'action'                        => $action,
            'reason'                        => null,
            'had_grade'                     => true,
            'old_value'                     => $oldValue,
            'new_value'                     => json_encode([
                'grade_value' => $gradeValue,
                'status'      => $newStatus,
                'remarks'     => $remarks,
            ]),
            'supporting_document_reference' => $data['supporting_document_reference'] ?? null,
            'user_role'                     => $role,
        ]);

        return null;
    }
}
