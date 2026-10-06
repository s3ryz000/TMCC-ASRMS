<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Error-log lines without student data (#58). A QueryException's message
 * carries the bound values of the failing query (names, birth dates,
 * addresses, grades); here a database error is described by its SQL with
 * placeholders and its error code only.
 */
class SafeLog
{
    public static function describe(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $state = $e->errorInfo[0] ?? $e->getCode();
            // MySQL quotes the offending value ("Duplicate entry 'x@y' for key ..."); mask it.
            $driverMessage = preg_replace("/'[^']*'/", "'?'", (string) ($e->errorInfo[2] ?? ''));

            return 'QueryException [' . $state . ']' . ($driverMessage !== '' ? " {$driverMessage}" : '')
                . ' SQL: ' . $e->getSql();
        }

        return get_class($e) . ': ' . $e->getMessage();
    }

    /** The context for a log entry about $e: where it happened, never the values. */
    public static function context(Throwable $e): array
    {
        return ['exception' => get_class($e), 'at' => basename($e->getFile()) . ':' . $e->getLine()];
    }
}
