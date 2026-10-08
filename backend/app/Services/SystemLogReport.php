<?php

namespace App\Services;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading system_logs (#94): one set of filters for the admin log, its PDF
 * export and a registrar's own activity. Newest first; each row carries the
 * user's name, username and role and the time in Manila.
 *
 * Timestamps are stored as wall-clock time in the application timezone
 * (Asia/Manila), so a date filter compares against local midnight.
 */
class SystemLogReport
{
    public const TIMEZONE = 'Asia/Manila';

    public const ROLES = ['admin', 'staff', 'student', 'guest', 'system'];

    /** Validation rules for the filters; $withUser is false for "my activity". */
    public static function rules(bool $withUser = true): array
    {
        return array_filter([
            'user_id' => $withUser ? ['nullable', 'integer'] : null,
            'role' => ['nullable', 'string', 'in:' . implode(',', self::ROLES)],
            'q' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** The filtered rows, newest first; $onlyUserId pins the user (my activity). */
    public function query(array $filters, ?int $onlyUserId = null): Builder
    {
        $userId = $onlyUserId ?? ($filters['user_id'] ?? null);

        return SystemLog::query()
            ->leftJoin('users', 'users.id', '=', 'system_logs.user_id')
            ->select('system_logs.*', 'users.name as user_name', 'users.username as username')
            ->when($userId !== null, fn ($q) => $q->where('system_logs.user_id', $userId))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('system_logs.role', $role))
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                // "!" escapes LIKE wildcards the same way on SQLite and MySQL.
                $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q']));
                $q->whereRaw("system_logs.action LIKE ? ESCAPE '!'", ["%{$term}%"]);
            })
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('system_logs.created_at', '>=', "{$d} 00:00:00"))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('system_logs.created_at', '<=', "{$d} 23:59:59"))
            ->orderByDesc('system_logs.created_at')
            ->orderByDesc('system_logs.log_id');
    }

    public static function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 15), 5), 100);
    }

    /** One row as the log pages show it. */
    public function present(SystemLog $log): array
    {
        $at = $log->created_at?->copy()->setTimezone(self::TIMEZONE);

        return [
            'log_id' => $log->log_id,
            'action' => $log->action,
            'user_id' => $log->user_id,
            'user_name' => $log->user_name,
            'username' => $log->username,
            'role' => $log->role,
            'logged_at' => $at?->format('Y-m-d H:i:s'),
            'logged_at_label' => $at?->format('M j, Y g:i:s A'),
        ];
    }

    /** The filters in words, for the PDF header. */
    public function describeFilters(array $filters, ?int $onlyUserId = null): string
    {
        $parts = [];
        $userId = $onlyUserId ?? ($filters['user_id'] ?? null);
        if ($userId !== null) {
            $user = User::find($userId);
            $parts[] = 'User: ' . ($user ? "{$user->name} ({$user->username})" : "#{$userId}");
        }
        if (filled($filters['role'] ?? null)) {
            $parts[] = 'Role: ' . $filters['role'];
        }
        if (filled($filters['q'] ?? null)) {
            $parts[] = 'Action contains: "' . trim($filters['q']) . '"';
        }
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if ($from || $to) {
            $parts[] = 'Dates: ' . ($from ?: 'start') . ' to ' . ($to ?: 'today');
        }

        return $parts ? implode(' · ', $parts) : 'None (all entries)';
    }

    /** The audit-log PDF's HTML: the filters in the header, then the rows. */
    public function pdfHtml(iterable $rows, string $filterSummary, int $total): string
    {
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $generatedAt = now(self::TIMEZONE)->format('F d, Y h:i A');
        $td = 'padding:4px 6px;border-bottom:0.4pt solid #ddd;';

        $body = '';
        $i = 0;
        foreach ($rows as $row) {
            $bg = ($i++ % 2 === 1) ? 'background:#f9f9f9;' : '';
            $user = $row['user_name'] ? "{$row['user_name']} ({$row['username']})" : '—';
            $body .= '<tr style="' . $bg . '">'
                . '<td style="' . $td . 'white-space:nowrap;">' . $e((string) $row['log_id']) . '</td>'
                . '<td style="' . $td . 'white-space:nowrap;">' . $e($row['logged_at']) . '</td>'
                . '<td style="' . $td . 'word-break:break-word;">' . $e($user) . '</td>'
                . '<td style="' . $td . '">' . $e($row['role']) . '</td>'
                . '<td style="' . $td . 'word-break:break-word;">' . $e($row['action']) . '</td>'
                . '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="5" style="padding:10px;text-align:center;color:#666;">No log entries match these filters.</td></tr>';
        }

        $th = 'padding:5px 6px;text-align:left;background:#7a0000;color:#fff;font-size:7.5pt;';
        $filters = $e($filterSummary);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 16mm 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; color: #111; margin:0; }
  .watermark {
    position: fixed; top: 46%; left: 50%;
    transform: translate(-50%, -50%) rotate(-32deg);
    font-size: 26pt; font-weight: bold;
    color: rgba(150,0,0,0.07);
    white-space: nowrap; z-index: -1;
    font-family: DejaVu Sans, sans-serif;
  }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  col.c1 { width: 6%; }
  col.c2 { width: 14%; }
  col.c3 { width: 18%; }
  col.c4 { width: 8%; }
  col.c5 { width: 54%; }
</style>
</head>
<body>
  <div class="watermark">ADMIN AUDIT LOG – DO NOT TAMPER</div>

  <div style="text-align:center;margin-bottom:12px;">
    <div style="font-size:12pt;font-weight:bold;">Trece Martires City College</div>
    <div style="font-size:10pt;font-weight:bold;margin-top:2px;">Admin Audit Log</div>
    <div style="font-size:7.5pt;color:#555;margin-top:2px;">Automated Student Records Management System</div>
  </div>

  <div style="font-size:7pt;color:#555;margin-bottom:4px;">
    Generated: {$generatedAt} (Manila time) &nbsp;|&nbsp; Total entries: {$total}
  </div>
  <div style="font-size:7pt;color:#333;margin-bottom:8px;">Filters: {$filters}</div>

  <table>
    <colgroup>
      <col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5">
    </colgroup>
    <thead>
      <tr>
        <th style="{$th}">Log ID</th>
        <th style="{$th}">Date &amp; Time</th>
        <th style="{$th}">User</th>
        <th style="{$th}">Role</th>
        <th style="{$th}">Action</th>
      </tr>
    </thead>
    <tbody>
      {$body}
    </tbody>
  </table>
</body>
</html>
HTML;
    }
}
