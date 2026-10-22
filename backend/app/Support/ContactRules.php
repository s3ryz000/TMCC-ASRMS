<?php

namespace App\Support;

/**
 * Contact number and e-mail rules shared by the student and user forms
 * (TMCC IT, 21 Oct 2026, #105).
 *
 * A Philippine mobile number may be typed as 09XXXXXXXXX or +639XXXXXXXXX,
 * with spaces or dashes; it is stored as 09XXXXXXXXX so every record reads
 * the same.
 */
final class ContactRules
{
    public const MOBILE_MESSAGE = 'Enter a mobile number as 09XXXXXXXXX or +639XXXXXXXXX.';

    public const EMAIL_MESSAGE = 'Enter a valid email address, e.g. name@gmail.com.';

    /** The stored form of a mobile number: 09XXXXXXXXX. */
    private const MOBILE_PATTERN = '/^09\d{9}$/';

    /**
     * The number in its stored form, or the input unchanged when it can't be
     * read as one (validation then reports it). Blank becomes null.
     */
    public static function normalizeMobile(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $digits = preg_replace('/[\s\-().]/', '', $value);
        if ($digits === '') {
            return null;
        }

        if (preg_match('/^\+?639(\d{9})$/', $digits, $m)) {
            return '09' . $m[1];
        }

        return $digits;
    }

    /** @return array<int, string> */
    public static function mobile(): array
    {
        return ['nullable', 'string', 'regex:' . self::MOBILE_PATTERN];
    }

    /**
     * RFC syntax plus PHP's filter, which also wants a domain such as
     * gmail.com, so "name@gmail" is refused.
     */
    public const EMAIL = 'email:rfc,filter';
}
