<?php

namespace App\Support;

/**
 * The one vocabulary for enrollment and grade statuses (#18).
 *
 * Statuses are stored exactly as written here. SQLite compares strings case
 * sensitively, so a query that spelled one differently ("enrolled") silently
 * matched nothing; every query, rule and validation now takes its values
 * from this class.
 */
final class AcademicStatus
{
    public const ENROLLED  = 'Enrolled';
    public const PASSED    = 'Passed';
    public const FAILED    = 'Failed';
    public const INC       = 'INC';
    public const WITHDRAWN = 'Withdrawn';
    public const FDA       = 'FDA';
    public const CREDITED  = 'Credited';
    public const DRP       = 'DRP';
    public const CON       = 'CON';
    public const CANCELLED = 'Cancelled';
    public const ARCHIVED  = 'Archived';

    /** Every status the system stores. */
    public const ALL = [
        self::ENROLLED, self::PASSED, self::FAILED, self::INC, self::WITHDRAWN, self::FDA,
        self::CREDITED, self::DRP, self::CON, self::CANCELLED, self::ARCHIVED,
    ];

    /** What grade entry may set (bulk and single). */
    public const GRADE = [
        self::ENROLLED, self::PASSED, self::FAILED, self::INC, self::WITHDRAWN, self::FDA,
        self::CREDITED, self::DRP, self::CON,
    ];

    /** What an enrollment's status may be set to by hand (#76). */
    public const ENROLLMENT_EDITABLE = [...self::GRADE, self::CANCELLED];

    /** Counted as passed: satisfies prerequisites and is never retaken. */
    public const PASSED_GROUP = [self::PASSED, self::CREDITED];

    /** A retake is offered after these. */
    public const RETAKE = [self::FAILED, self::WITHDRAWN, self::FDA];

    /** An outcome has been recorded for the subject. */
    public const RECORDED = [self::PASSED, self::FAILED, self::INC, self::WITHDRAWN, self::FDA, self::CREDITED];

    /** The term is finished for the subject (progression may move on). */
    public const FINAL = [...self::RECORDED, self::CANCELLED];

    /** Not part of the student's current load. */
    public const NOT_ACTIVE = [self::CANCELLED, self::ARCHIVED];

    /** No longer occupies a seat, so the subject may be enrolled again. */
    public const CLOSED = [self::ARCHIVED, self::CANCELLED, self::FAILED, self::WITHDRAWN, self::FDA, self::DRP];

    /** Older spellings that mean a canonical status. */
    private const ALIASES = ['dropped' => self::DRP];

    /**
     * The canonical form of a status, matched without regard to case
     * ("enrolled" -> "Enrolled", "inc" -> "INC"), or null if it isn't one.
     *
     * @param  string[]  $allowed  restrict the result to these statuses
     */
    public static function canonical(?string $value, array $allowed = self::ALL): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        $status = self::ALIASES[strtolower($value)]
            ?? collect(self::ALL)->first(fn (string $s) => strcasecmp($s, $value) === 0);

        return $status !== null && in_array($status, $allowed, true) ? $status : null;
    }
}
