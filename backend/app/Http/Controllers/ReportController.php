<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\SystemLog;
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
        $processedToday = Student::whereDate('created_at', today())->count();
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
            'approval_rate' => $this->approvalRate(),
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
                'error' => $e->getMessage()
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

        $query = RecordRequest::with('student:student_id,student_number,first_name,last_name,email')
            ->orderByDesc('requested_at');

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('requested_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('requested_at', '<=', $dateTo);
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

        return response()->json([
            'export_data' => $data,
            'summary' => [
                'total_requests' => RecordRequest::count(),
                'avg_processing_time_days' => $this->avgProcessingTimeDays(),
                'approval_rate' => $this->approvalRate(),
            ],
        ]);
    }

    private function approvalRate(): ?float
    {
        $total = RecordRequest::whereIn('status', [RecordRequest::STATUS_APPROVED, RecordRequest::STATUS_REJECTED])->count();
        if ($total === 0) {
            return null;
        }
        $approved = RecordRequest::where('status', RecordRequest::STATUS_APPROVED)->count()
            + RecordRequest::where('status', RecordRequest::STATUS_RELEASED)->count();
        SystemLog::create([
            'action' => 'Approval rate calculated',
            'user_id' => auth()->user()->id,
            'role' => auth()->user()->roles->first()?->name ?? auth()->user()->role ?? null,
        ]);
        return round($approved / $total * 100, 2);
    }

    private function avgProcessingTimeDays(): ?float
    {
        $avgDays = RecordRequest::whereNotNull('processed_at')
            ->whereNotNull('requested_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, requested_at, processed_at)) as avg_days')
            ->value('avg_days');

        return $avgDays !== null ? round((float) $avgDays, 2) : null;
    }
}
