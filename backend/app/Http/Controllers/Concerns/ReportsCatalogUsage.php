<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What still refers to a subject or program. The foreign keys restrict
 * deletes (#15), so the catalogue controllers check this first and explain
 * what is in the way instead of surfacing a database error.
 *
 * A usage map is [label => [table, column or columns]]. Soft-deleted rows
 * count: the database still holds them.
 */
trait ReportsCatalogUsage
{
    /**
     * Non-zero reference counts for one row, keyed by label.
     *
     * @param array<string, array{0: string, 1: string|string[]}> $usage
     * @return array<string, int>
     */
    protected function usageCounts(array $usage, int $id): array
    {
        $counts = [];
        foreach ($usage as $label => [$table, $columns]) {
            $count = DB::table($table)
                ->where(function ($q) use ($columns, $id) {
                    foreach ((array) $columns as $column) {
                        $q->orWhere($column, $id);
                    }
                })
                ->count();

            if ($count > 0) {
                $counts[$label] = $count;
            }
        }

        return $counts;
    }

    /**
     * Ids referenced by anything in the usage map, for list views.
     *
     * @param array<string, array{0: string, 1: string|string[]}> $usage
     * @return array<int, true>
     */
    protected function idsInUse(array $usage): array
    {
        $ids = [];
        foreach ($usage as [$table, $columns]) {
            foreach ((array) $columns as $column) {
                foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return $ids;
    }

    /** "3 curriculum entries and 12 grades" */
    protected function describeUsage(array $counts): string
    {
        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = $count . ' ' . Str::plural($label, $count);
        }

        return count($parts) > 1
            ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts)
            : ($parts[0] ?? '');
    }
}
