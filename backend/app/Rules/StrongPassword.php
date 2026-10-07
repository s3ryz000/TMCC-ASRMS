<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one password rule for every place a password is set (#87): creating a
 * user, an administrator's reset and changing your own password.
 *
 * At least 10 characters with letters and numbers, not the account's username
 * or e-mail name, and not on the built-in list of common passwords. The list
 * ships with the code because the system runs on a LAN with no internet.
 * Existing passwords are never re-checked; the rule applies when one is set.
 */
class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 10;

    public const HINT = 'Use at least 10 characters with letters and numbers.';

    private const COMMON_LIST = 'resources/security/common-passwords.txt';

    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(
        private readonly ?string $username = null,
        private readonly ?string $email = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // the 'string' rule beside this one reports it
        }

        if ($problem = self::problem($value, $this->username, $this->email)) {
            $fail($problem);
        }
    }

    /**
     * Why the password is refused, or null when it is acceptable.
     */
    public static function problem(string $password, ?string $username = null, ?string $email = null): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH
            || ! preg_match('/\pL/u', $password)
            || ! preg_match('/\pN/u', $password)) {
            return self::HINT;
        }

        $lower = mb_strtolower($password);

        $names = array_filter([
            $username,
            $email !== null && str_contains($email, '@') ? strstr($email, '@', true) : $email,
        ], fn ($name) => is_string($name) && $name !== '');

        foreach ($names as $name) {
            if ($lower === mb_strtolower($name)) {
                return 'The password must not be the same as the username or e-mail name.';
            }
        }

        if (isset(self::commonPasswords()[$lower])) {
            return 'This password is too common. Choose one that is harder to guess.';
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private static function commonPasswords(): array
    {
        if (self::$common === null) {
            $lines = file(base_path(self::COMMON_LIST), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $words = array_map(fn ($line) => mb_strtolower(trim($line)), $lines);
            $words = array_filter($words, fn ($word) => $word !== '' && ! str_starts_with($word, '#'));
            self::$common = array_fill_keys($words, true);
        }

        return self::$common;
    }
}
