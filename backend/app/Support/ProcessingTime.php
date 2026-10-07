<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Average record-request processing time, computed in PHP so it is the same
 * on SQLite (local, UAT) and MySQL (deployment) (#84). The database only
 * selects the two timestamps; no date arithmetic happens in SQL.
 *
 * A request's time is the whole days from requested_at to processed_at,
 * rounded down, the meaning the report has always had (MySQL's
 * TIMESTAMPDIFF(DAY, ...)).
 */
final class ProcessingTime
{
    /**
     * @param iterable<array{0: ?CarbonInterface, 1: ?CarbonInterface}> $pairs [requested_at, processed_at]
     */
    public static function averageDays(iterable $pairs): ?float
    {
        $total = 0;
        $count = 0;

        foreach ($pairs as [$requestedAt, $processedAt]) {
            if ($requestedAt === null || $processedAt === null) {
                continue;
            }
            $total += self::wholeDays($requestedAt, $processedAt);
            $count++;
        }

        return $count === 0 ? null : round($total / $count, 2);
    }

    public static function wholeDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }
}
