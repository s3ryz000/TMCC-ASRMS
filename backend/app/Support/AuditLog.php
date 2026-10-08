<?php

namespace App\Support;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One way to write sign-in, account and settings events to system_logs
 * (#95), so they read the same: "Login: staff", "Failed login: unknown user
 * 'adm1n'", "User updated: uat.staff — role staff → admin". Passwords are
 * never passed in. system_logs is append-only: nothing in the application
 * updates or deletes a row.
 */
class AuditLog
{
    /** The row's actor is $actor; without one it is "system", or $role when given (e.g. guest). */
    public static function write(string $action, ?User $actor = null, ?string $role = null): SystemLog
    {
        return SystemLog::create([
            'action' => $action,
            'user_id' => $actor?->id,
            'role' => $role ?? ($actor ? ($actor->roles->first()?->name ?? $actor->role ?? 'system') : 'system'),
        ]);
    }

    /** Text typed by someone not signed in, made safe to store: no control characters, at most 60 characters. */
    public static function typed(?string $value): string
    {
        return Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value) ?? '', 60);
    }

    /** A value in an "old → new" pair. */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '', $value === [] => '(none)',
            is_bool($value) => $value ? 'on' : 'off',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
