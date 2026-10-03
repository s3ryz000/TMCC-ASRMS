<?php

namespace App\Services\Enrollment;

use App\Support\AcademicStatus;
use App\Models\Grade;
use App\Models\Student;

/**
 * Single source of truth for "what has this student already completed?".
 *
 * This question used to be answered in three places that disagreed with each
 * other. StudentController::storeEnrollment accepted only a numeric grade of
 * 1.00-3.00 or the exact string 'PASSED', so a subject marked Credited with no
 * numeric grade was invisible to it and its prerequisite would be reported as
 * unmet. The definitions below are the ones the progression service has always
 * used, and they now apply everywhere.
 */
class AcademicRecordQuery
{
    /** Statuses that count as "passed" for prerequisite purposes. */
    public const PASSED_STATUSES = AcademicStatus::PASSED_GROUP;

    /**
     * Subject IDs the student has passed or been credited for.
     *
     * @return int[]
     */
    public function passedSubjectIds(Student $student): array
    {
        return Grade::where('student_id', $student->student_id)
            ->where(function ($q) {
                $q->whereIn('status', self::PASSED_STATUSES)
                    ->orWhere(function ($inner) {
                        // Legacy rows written before the status column existed.
                        $inner->whereNotNull('grade_value')
                            ->where('grade_value', '>=', 1.00)
                            ->where('grade_value', '<=', 3.00)
                            ->whereNull('status');
                    })
                    ->orWhere(function ($inner) {
                        // Legacy rows that recorded the outcome in remarks only.
                        $inner->whereNull('status')
                            ->whereIn('remarks', ['PASSED', AcademicStatus::PASSED, 'CREDITED', AcademicStatus::CREDITED]);
                    });
            })
            ->pluck('subject_id')
            // Cast explicitly: PDO can hand back integer columns as strings
            // depending on driver settings, and the rules compare strictly.
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Subject IDs sitting at INC. An INC does not satisfy a prerequisite.
     *
     * @return int[]
     */
    public function incSubjectIds(Student $student): array
    {
        return Grade::where('student_id', $student->student_id)
            ->where(function ($q) {
                $q->where('status', AcademicStatus::INC)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('status')
                            ->where(function ($sub) {
                                $sub->where('grade_value', 4.00)
                                    ->orWhereIn('remarks', [AcademicStatus::INC, 'inc']);
                            });
                    });
            })
            ->pluck('subject_id')
            // Cast explicitly: PDO can hand back integer columns as strings
            // depending on driver settings, and the rules compare strictly.
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
