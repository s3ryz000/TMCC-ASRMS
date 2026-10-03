<?php

namespace App\Support;

use App\Models\Subject;
use App\Services\Enrollment\EnrollmentTerm;
use Closure;

/**
 * Placement rules shared by the curriculum entries API (#24) and creating a
 * program with its curriculum (#70): Year 1-4, 1st or 2nd semester only
 * (no silent default), a subject once per program, no archived subjects.
 */
class CurriculumRules
{
    public const YEAR_LEVEL = ['required', 'integer', 'between:1,4'];

    /** The semester rule; anything normaliseSemester() doesn't recognise is refused. */
    public static function semester(): array
    {
        return ['required', function (string $attribute, mixed $value, Closure $fail) {
            if (self::semesterNumber($value) === null) {
                $fail('The semester must be 1st or 2nd.');
            }
        }];
    }

    /** 1 or 2, or null when the value isn't a semester. */
    public static function semesterNumber(mixed $value): ?int
    {
        return is_int($value) || is_string($value) ? EnrollmentTerm::normaliseSemester($value) : null;
    }

    public static function archivedSubject(Subject $subject): string
    {
        return "{$subject->code} {$subject->title} is archived and can't be placed in a curriculum.";
    }

    public static function alreadyPlaced(string $subjectCode, string $programCode, int|string $yearLevel, int|string $semester): string
    {
        return "{$subjectCode} is already in {$programCode}, " . self::termLabel($yearLevel, $semester) . '.';
    }

    /** "Year 1 1st semester" */
    public static function termLabel(int|string $yearLevel, int|string $semester): string
    {
        return 'Year ' . (int) $yearLevel . ' ' . ((int) $semester === 1 ? '1st' : '2nd') . ' semester';
    }

    /** "Y1 S2", for the system log. */
    public static function termShort(int|string $yearLevel, int|string $semester): string
    {
        return 'Y' . (int) $yearLevel . ' S' . (int) $semester;
    }
}
