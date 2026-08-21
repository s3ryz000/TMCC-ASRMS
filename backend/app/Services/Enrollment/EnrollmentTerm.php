<?php

namespace App\Services\Enrollment;

/**
 * The term an enrollment is being recorded against.
 *
 * Deliberately a value object rather than three loose arguments: the three
 * fields are meaningless apart and were previously passed around as a mix of
 * ints, strings and array keys, which is how Path A ended up able to record a
 * 2nd-semester enrollment against semester 1.
 */
class EnrollmentTerm
{
    public function __construct(
        public readonly int $yearLevel,
        public readonly int $semester,
        public readonly string $academicYear,
    ) {
    }

    /**
     * Normalise the semester formats used across the app ("1st", "2nd",
     * "1st Semester", 1, "1") to the integer stored in the curriculum table.
     *
     * Returns null for anything unrecognised so callers can reject it. The old
     * `$SEMESTER_MAPPING[...] ?? 1` silently turned an unknown value into 1st
     * semester, which corrupted the record instead of refusing it.
     */
    public static function normaliseSemester(mixed $semester): ?int
    {
        if (is_int($semester)) {
            return in_array($semester, [1, 2], true) ? $semester : null;
        }

        $value = strtolower(trim((string) $semester));

        if ($value === '1' || str_starts_with($value, '1st')) {
            return 1;
        }
        if ($value === '2' || str_starts_with($value, '2nd')) {
            return 2;
        }

        return null;
    }

    public function label(): string
    {
        return "Year {$this->yearLevel}, Semester {$this->semester}, A.Y. {$this->academicYear}";
    }
}
