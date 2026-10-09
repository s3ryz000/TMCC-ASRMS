<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\SystemLog;
use App\Services\ReportFigures;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reports: KPIs and transaction history. Staff: read-only. Admin: full + export.
 */
class ReportController extends Controller
{
    use AuthorizesRole;

    public function __construct(private readonly ReportFigures $figures)
    {
    }

    /**
     * Summary KPIs for dashboard (staff + admin).
     */
    public function summary(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $pendingCount = RecordRequest::where('status', RecordRequest::STATUS_PENDING)->count();
        $processedToday = $this->figures->processedToday();
        $studentsCount = Student::count();
        $releasedToday = RecordRequest::where('status', RecordRequest::STATUS_RELEASED)
            ->whereDate('released_at', today())
            ->count();
        $documentsReleasedTotal = RecordRequest::where('status', RecordRequest::STATUS_RELEASED)->count();

        return response()->json([
            'pending_requests' => $pendingCount,
            'processed_today' => $processedToday,
            'students_count' => $studentsCount,
            'documents_released_today' => $releasedToday,
            'documents_released_total' => $documentsReleasedTotal,
            // All time; approved ÷ decided, 0–100 (#96).
            'approval_rate' => ReportFigures::approvalRate($this->figures->requestsByStatus()),
        ]);
    }

    /**
     * Transaction history with filters (staff: read-only list; admin: full with export capability).
     */
    public function transactionHistory(Request $request): JsonResponse
    {
        try {
            if ($err = $this->requireAuth()) {
                return $err;
            }
            if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
                return $err;
            }
    
            $query = SystemLog::with('user'); 
    
            if ($dateFrom = $request->input('date_from')) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo = $request->input('date_to')) {
                $query->whereDate('created_at', '<=', $dateTo);
            }
            if ($role = $request->input('role')) {
                $query->where('role', $role);
            }
    
            $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
    
            $items = $query->latest()->paginate($perPage);
    
            $items->getCollection()->transform(function ($log) {
                return [
                    'id' => $log->id,
                    'user_name' => $log->user 
                        ? $log->user->name 
                        : 'System',
                    'action' => $log->action,
                    'role' => ucfirst($log->role),
                    'date' => $log->created_at->format('Y-m-d'),
                    'time' => $log->created_at->format('h:i A'),
                ];
            });
    
            return response()->json($items);
    
        } catch (\Exception $e) {
            Log::error('error while fetching logs: ' . SafeLog::describe($e), SafeLog::context($e));
            return response()->json([
                'message' => 'Failed to get transaction history',
                'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong.',
            ], 500);
        }
    }

    /**
     * Export data (admin only). Returns structured data for CSV/PDF generation on frontend or server.
     */
    public function export(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $range = $this->dateRange($request);

        $query = RecordRequest::with('student:student_id,student_number,first_name,last_name,email')
            ->orderByDesc('requested_at');

        if ($range['date_from']) {
            $query->whereDate('requested_at', '>=', $range['date_from']);
        }
        if ($range['date_to']) {
            $query->whereDate('requested_at', '<=', $range['date_to']);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $data = $query->get()->map(function ($req) {
            $s = $req->student;
            return [
                'id' => $req->id,
                'student_number' => $s?->student_number,
                'student_name' => $s ? trim($s->first_name . ' ' . $s->last_name) : null,
                'record_type' => $req->record_type,
                'purpose' => $req->purpose,
                'status' => $req->status,
                'requested_at' => $req->requested_at?->toIso8601String(),
                'processed_at' => $req->processed_at?->toIso8601String(),
                'released_at' => $req->released_at?->toIso8601String(),
            ];
        });

        // The summary covers the same date range as the rows (#96).
        $report = $this->figures->requests($range['date_from'], $range['date_to']);

        return response()->json([
            'export_data' => $data,
            'summary' => [
                'range' => $report['range'],
                'total_requests' => $report['total'],
                'by_status' => $report['by_status'],
                'by_record_type' => $report['by_record_type'],
                'avg_processing_time_days' => $report['avg_processing_time_days'],
                'approval_rate' => $report['approval_rate'],
            ],
        ]);
    }

    /**
     * Requests report for a date range (admin only, #96): counts per status
     * and record type, approval rate and average processing time.
     */
    public function requestsReport(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $range = $this->dateRange($request);

        return response()->json($this->figures->requests($range['date_from'], $range['date_to']));
    }

    /**
     * Activity report for a date range from the system logs (admin only,
     * #96): entries per day and per role, and the most frequent actions.
     */
    public function activityReport(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $range = $this->dateRange($request);

        return response()->json($this->figures->activity($range['date_from'], $range['date_to']));
    }

    /**
     * Optional inclusive date range, as Y-m-d.
     *
     * @return array{date_from: ?string, date_to: ?string}
     */
    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        return [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }
}
