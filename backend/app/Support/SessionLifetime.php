<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * How long a login lasts (#86): it ends after ASRMS_SESSION_IDLE_MINUTES
 * without a request (default 60) and, regardless of activity,
 * ASRMS_SESSION_MAX_HOURS after signing in (default 12).
 *
 * Sanctum calls accept() for every bearer token it validates, before it
 * stamps the token's last_used_at with the current time, so the idle check
 * reads the time of the previous request at no extra cost. An ended token is
 * deleted, so it can never be used again.
 */
class SessionLifetime
{
    public static function idleMinutes(): int
    {
        return (int) config('asrms.session.idle_minutes', 60);
    }

    public static function maxHours(): int
    {
        return (int) config('asrms.session.max_hours', 12);
    }

    /** Sanctum's token check: false refuses the token (401). */
    public static function accept(PersonalAccessToken $token, bool $isValid): bool
    {
        if (! $isValid) {
            if (self::isPastLifetime($token)) {
                $token->delete();
            }

            return false;
        }

        if (self::isIdle($token)) {
            $token->delete();

            return false;
        }

        return true;
    }

    /** No request for longer than the idle window (0 turns the idle check off). */
    public static function isIdle(PersonalAccessToken $token): bool
    {
        $minutes = self::idleMinutes();
        $lastSeen = $token->last_used_at ?? $token->created_at;

        return $minutes > 0 && $lastSeen !== null && $lastSeen->lt(now()->subMinutes($minutes));
    }

    /** Older than the absolute lifetime, by its own expires_at or by its age. */
    public static function isPastLifetime(PersonalAccessToken $token): bool
    {
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return true;
        }
        $minutes = (int) config('sanctum.expiration');

        return $minutes > 0 && $token->created_at !== null && $token->created_at->lte(now()->subMinutes($minutes));
    }
}
