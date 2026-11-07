<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use DateTimeInterface;

/**
 * Student numbers (#56) in TMCC's own format: a capital letter, the 2-digit
 * enrollment year, a hyphen and 4 digits the registrar chooses, e.g. A26-0001
 * for the first student enrolled in 2026. Current students are "A"; some
 * older records use other letters, so any A-Z is accepted (what the letter
 * stands for is still to be confirmed with the TMCC Registrar). The number is
 * also the student's login username, so a number is free only when neither a
 * student nor a user account holds it.
 */
class StudentNumber
{
    public const PATTERN = '/^[A-Z]\d{2}-\d{4}$/';

    public const FORMAT_MESSAGE = 'The student number must be a capital letter, the 2-digit enrollment year, a hyphen and 4 digits, e.g. A26-0001.';

    /** The letter the New Student form starts with. */
    public const DEFAULT_LETTER = 'A';

    /**
     * The number as typed, tidied: spaces removed, the letter capitalized and
     * the hyphen added when it was left out ("a26 0001" or "A260001" become
     * "A26-0001"). Anything else comes back without its spaces.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = preg_replace('/\s+/', '', $value);
        if (preg_match('/^([A-Za-z])(\d{2})-?(\d{4})$/', $value, $m)) {
            return strtoupper($m[1]) . $m[2] . '-' . $m[3];
        }

        return $value;
    }

    /** The enrollment-year digits of a number: "26" for A26-0001. */
    public static function yearOf(string $number): ?string
    {
        return self::isValid($number) ? substr($number, 1, 2) : null;
    }

    /** Letter, year and hyphen: "A26-" for A26-0001, the prefix the sequence counts under. */
    public static function prefixOf(string $number): ?string
    {
        return self::isValid($number) ? substr($number, 0, 4) : null;
    }

    /** "26" with letter "A" = "A26-". */
    public static function prefix(string $yearDigits, string $letter = self::DEFAULT_LETTER): string
    {
        return strtoupper($letter) . $yearDigits . '-';
    }

    public static function isValid(mixed $number): bool
    {
        return is_string($number) && preg_match(self::PATTERN, $number) === 1;
    }

    /**
     * "26" for an enrollment date in 2026. A YYYY-MM-DD value is read as
     * written (#77), never through a timezone conversion.
     */
    public static function yearPrefix(mixed $enrollmentDate): ?string
    {
        if ($enrollmentDate instanceof DateTimeInterface) {
            return $enrollmentDate->format('y');
        }
        if (is_string($enrollmentDate) && preg_match('/^\s*(\d{4})-\d{2}-\d{2}/', $enrollmentDate, $m)) {
            return substr($m[1], 2, 2);
        }

        return null;
    }

    /** The registrar's 4 digits after the prefix: "A26-" + "0004" = "A26-0004". */
    public static function compose(string $prefix, string $part): string
    {
        return $prefix . $part;
    }

    /** "The 2 digits after the letter must be 26, the enrollment year." */
    public static function wrongYearMessage(string $yearPrefix): string
    {
        return "The 2 digits after the letter must be {$yearPrefix}, the enrollment year.";
    }

    /**
     * Whether the number is free: no other student has it and no other
     * account uses it as a username.
     */
    public static function isAvailable(string $number, ?Student $except = null): bool
    {
        $studentTaken = Student::where('student_number', $number)
            ->when($except, fn ($q) => $q->whereKeyNot($except->student_id))
            ->exists();
        $usernameTaken = User::where('username', $number)
            ->when($except?->user_id, fn ($q) => $q->whereKeyNot($except->user_id))
            ->exists();

        return ! $studentTaken && ! $usernameTaken;
    }

    /**
     * The next free number under a prefix ("A26-"): one after the highest
     * number in use with that prefix, or the lowest gap once 9999 is reached;
     * null when the prefix is full.
     */
    public static function nextAvailable(string $prefix): ?string
    {
        $used = Student::where('student_number', 'like', "{$prefix}%")->pluck('student_number')
            ->merge(User::where('username', 'like', "{$prefix}%")->pluck('username'))
            // LIKE can ignore case; only exact-prefix numbers count.
            ->filter(fn ($n) => self::isValid($n) && str_starts_with($n, $prefix))
            ->map(fn ($n) => (int) substr($n, 4))
            ->unique();

        $candidates = $used->isEmpty() ? [1] : [$used->max() + 1];
        if ($candidates[0] > 9999) {
            $candidates = range(1, 9999);
        }

        $taken = $used->flip();
        foreach ($candidates as $n) {
            if ($n <= 9999 && ! isset($taken[$n])) {
                return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
        }

        return null;
    }

    /** The student holding a number, if any. */
    public static function holder(string $number, ?Student $except = null): ?Student
    {
        return Student::with('program:id,code')
            ->where('student_number', $number)
            ->when($except, fn ($q) => $q->whereKeyNot($except->student_id))
            ->first();
    }

    /**
     * What the screen may show about the holder of a taken number (decided
     * 29 Sep). With $comparison false only the name and program, for the live
     * availability check; with it, also the enrollment and birth dates for the
     * side-by-side card after a refused save.
     */
    public static function conflict(string $number, bool $comparison, ?Student $except = null): ?array
    {
        $holder = self::holder($number, $except);
        if (! $holder) {
            // Held by a non-student login only: say so, show nothing about it.
            return self::isAvailable($number, $except) ? null : ['student_number' => $number, 'name' => null, 'program' => null];
        }

        $card = [
            'student_number' => $holder->student_number,
            'name'           => trim("{$holder->first_name} {$holder->last_name}"),
            'program'        => $holder->program?->code,
        ];

        return $comparison ? $card + [
            'enrollment_date' => self::plainDate($holder->getRawOriginal('enrollment_date')),
            'date_of_birth'   => self::plainDate($holder->getRawOriginal('date_of_birth')),
        ] : $card;
    }

    /** The 422 body for a taken number: field error, the comparison card and the next free number. */
    public static function takenResponse(string $number, ?Student $except = null): array
    {
        $conflict = self::conflict($number, true, $except);
        $who = ($conflict['name'] ?? null)
            ? $conflict['name'] . ($conflict['program'] ? " ({$conflict['program']})" : '')
            : 'another account';
        $message = "Student number {$number} is already used by {$who}.";

        return [
            'message'        => $message,
            'errors'         => ['student_number' => [$message]],
            'conflict'       => $conflict,
            'next_available' => ($prefix = self::prefixOf($number)) ? self::nextAvailable($prefix) : null,
        ];
    }

    private static function plainDate(?string $value): ?string
    {
        return $value ? substr($value, 0, 10) : null;
    }
}
