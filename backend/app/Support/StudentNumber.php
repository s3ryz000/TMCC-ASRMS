<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use DateTimeInterface;

/**
 * Student numbers (#56): the 2-digit enrollment year followed by 4 digits the
 * registrar chooses, e.g. 260001 for the first student enrolled in 2026. The
 * number is also the student's login username, so a number is free only when
 * neither a student nor a user account holds it.
 */
class StudentNumber
{
    public const PATTERN = '/^\d{2}\d{4}$/';

    public const FORMAT_MESSAGE = 'The student number must be 6 digits: the 2-digit enrollment year and 4 digits, e.g. 260001.';

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

    /** The registrar's 4 digits after the year prefix: "26" + "0004" = "260004". */
    public static function compose(string $yearPrefix, string $part): string
    {
        return $yearPrefix . $part;
    }

    /** "The first two digits must be 26, the enrollment year." */
    public static function wrongYearMessage(string $yearPrefix): string
    {
        return "The first two digits must be {$yearPrefix}, the enrollment year.";
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
     * The next free number for a year: one after the highest number in use
     * with that prefix, or the lowest gap once 9999 is reached; null when the
     * year is full.
     */
    public static function nextAvailable(string $yearPrefix): ?string
    {
        $used = Student::where('student_number', 'like', "{$yearPrefix}%")->pluck('student_number')
            ->merge(User::where('username', 'like', "{$yearPrefix}%")->pluck('username'))
            ->filter(fn ($n) => self::isValid($n))
            ->map(fn ($n) => (int) substr($n, 2))
            ->unique();

        $candidates = $used->isEmpty() ? [1] : [$used->max() + 1];
        if ($candidates[0] > 9999) {
            $candidates = range(1, 9999);
        }

        $taken = $used->flip();
        foreach ($candidates as $n) {
            if ($n <= 9999 && ! isset($taken[$n])) {
                return $yearPrefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
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
            'next_available' => self::nextAvailable(substr($number, 0, 2)),
        ];
    }

    private static function plainDate(?string $value): ?string
    {
        return $value ? substr($value, 0, 10) : null;
    }
}
