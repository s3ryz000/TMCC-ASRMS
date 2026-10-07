<?php

namespace App\Services;

use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\SystemLog;
use App\Models\User;
use App\Support\ProcessingTime;
use Carbon\CarbonImmutable;

/**
 * Report and dashboard figures (#96), computed from the database when they
 * are asked for. Rows are selected with plain column lists and counted in
 * PHP, so SQLite (local, UAT) and MySQL (deployment) give the same figures
 * (#84). Dates are whole days in the application timezone, both ends
 * included; a request belongs to the day it was requested, a log entry to
 * the day it was written.
 */
class ReportFigures
{
    public const STATUSES = [
        RecordRequest::STATUS_PENDING,
        RecordRequest::STATUS_APPROVED,
        RecordRequest::STATUS_REJECTED,
        RecordRequest::STATUS_RELEASED,
    ];

    private const TOP_ACTIONS = 10;

    /**
     * Record requests made in the range: counts per status and record type,
     * approval rate and average processing time.
     */
    public function requests(?string $dateFrom, ?string $dateTo): array
    {
        $rows = $this->inRange(RecordRequest::query(), 'requested_at', $dateFrom, $dateTo)
            ->toBase()
            ->get(['status', 'record_type', 'requested_at', 'processed_at']);

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $byStatus[$row->status] = ($byStatus[$row->status] ?? 0) + 1;
        }

        $byType = $rows->countBy('record_type')
            ->map(fn ($total, $type) => ['record_type' => $type, 'total' => $total])
            ->sortBy([['total', 'desc'], ['record_type', 'asc']])
            ->values()
            ->all();

        $parse = fn ($value) => $value === null ? null : CarbonImmutable::parse($value);

        return [
            'range' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'total' => $rows->count(),
            'by_status' => $byStatus,
            'by_record_type' => $byType,
            'decided' => self::decided($byStatus),
            'approval_rate' => self::approvalRate($byStatus),
            'avg_processing_time_days' => ProcessingTime::averageDays(
                $rows->map(fn ($row) => [$parse($row->requested_at), $parse($row->processed_at)])
            ),
        ];
    }

    /**
     * System log entries written in the range: per day, per role and the
     * most frequent actions.
     */
    public function activity(?string $dateFrom, ?string $dateTo): array
    {
        $rows = $this->inRange(SystemLog::query(), 'created_at', $dateFrom, $dateTo)
            ->toBase()
            ->get(['action', 'role', 'created_at']);

        $byDay = $rows->countBy(fn ($row) => CarbonImmutable::parse($row->created_at)->toDateString())
            ->sortKeys()
            ->map(fn ($total, $date) => ['date' => $date, 'total' => $total])
            ->values()
            ->all();

        $byRole = $rows->countBy(fn ($row) => $row->role ? strtolower($row->role) : 'system')
            ->map(fn ($total, $role) => ['role' => $role, 'total' => $total])
            ->sortBy([['total', 'desc'], ['role', 'asc']])
            ->values()
            ->all();

        $topActions = $rows->countBy('action')
            ->map(fn ($total, $action) => ['action' => $action, 'total' => $total])
            ->sortBy([['total', 'desc'], ['action', 'asc']])
            ->take(self::TOP_ACTIONS)
            ->values()
            ->all();

        return [
            'range' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'total' => $rows->count(),
            'by_day' => $byDay,
            'by_role' => $byRole,
            'top_actions' => $topActions,
        ];
    }

    /**
     * Approved ÷ decided, as a percentage from 0 to 100, or null when nothing
     * has been decided. A released request was approved first, so it counts
     * as approved; pending requests are not decided yet. (The old figure
     * divided approved + released by approved + rejected and reached 200%.)
     *
     * @param array<string, int> $byStatus
     */
    public static function approvalRate(array $byStatus): ?float
    {
        $decided = self::decided($byStatus);
        if ($decided === 0) {
            return null;
        }

        $approved = ($byStatus[RecordRequest::STATUS_APPROVED] ?? 0) + ($byStatus[RecordRequest::STATUS_RELEASED] ?? 0);

        return round($approved / $decided * 100, 2);
    }

    /** @param array<string, int> $byStatus */
    private static function decided(array $byStatus): int
    {
        return ($byStatus[RecordRequest::STATUS_APPROVED] ?? 0)
            + ($byStatus[RecordRequest::STATUS_RELEASED] ?? 0)
            + ($byStatus[RecordRequest::STATUS_REJECTED] ?? 0);
    }

    /** Request counts per status over all time; every status is present. */
    public function requestsByStatus(): array
    {
        return array_merge(
            array_fill_keys(self::STATUSES, 0),
            RecordRequest::query()->toBase()->pluck('status')->countBy()->all(),
        );
    }

    /**
     * Admin dashboard totals: users per role split by active/inactive,
     * students, and requests per status.
     */
    public function dashboardTotals(): array
    {
        $users = [];
        foreach (['admin', 'staff', 'student'] as $role) {
            $users[$role] = ['active' => 0, 'inactive' => 0, 'total' => 0];
        }

        foreach (User::query()->toBase()->get(['role', 'status']) as $user) {
            $role = $user->role ?: 'unknown';
            $status = $user->status === 'inactive' ? 'inactive' : 'active';
            $users[$role] ??= ['active' => 0, 'inactive' => 0, 'total' => 0];
            $users[$role][$status]++;
            $users[$role]['total']++;
        }

        return [
            'users' => $users,
            'students' => Student::count(),
            'requests' => $this->requestsByStatus(),
        ];
    }

    /**
     * Rows whose $column falls on a day from $dateFrom to $dateTo, both
     * included. Plain comparisons on the stored value, the same on SQLite and
     * MySQL; either end may be left open.
     */
    private function inRange($query, string $column, ?string $dateFrom, ?string $dateTo)
    {
        if ($dateFrom) {
            $query->where($column, '>=', CarbonImmutable::parse($dateFrom)->startOfDay()->toDateTimeString());
        }
        if ($dateTo) {
            $query->where($column, '<', CarbonImmutable::parse($dateTo)->addDay()->startOfDay()->toDateTimeString());
        }

        return $query;
    }
}
