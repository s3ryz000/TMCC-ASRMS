<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\PendingStudentUpdate;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\StudentNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PendingStudentUpdateController extends Controller
{
    use AuthorizesRole;

    public function __construct(
        private StudentNotifier $notifier,
    ) {}

    /**
     * List profile updates, all or by ?status= (pending, approved, rejected,
     * revision_required). Admins read the same list (#88).
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:' . implode(',', PendingStudentUpdate::STATUSES)],
        ]);

        $updates = PendingStudentUpdate::with(['student:student_id,student_number,first_name,last_name,program_id', 'student.program', 'submitter:id,name', 'reviewer:id,name'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($updates);
    }

    /**
     * View a specific pending update.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $update = PendingStudentUpdate::with(['student', 'submitter', 'reviewer'])->find($id);

        if (!$update) {
            return response()->json(['message' => 'Pending update not found.'], 404);
        }

        return response()->json($update);
    }

    /**
     * Approve a pending update.
     *
     * Registrar staff only: approving writes the change onto the student
     * record, which is record-keeping rather than system governance.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $update = PendingStudentUpdate::with('student')->find($id);

        if (!$update) {
            return response()->json(['message' => 'Pending update not found.'], 404);
        }

        if ($update->status !== PendingStudentUpdate::STATUS_PENDING) {
            return response()->json(['message' => 'This update has already been processed.'], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($update, $user) {
            // Apply new values
            $update->student->update($update->new_values);

            $this->decide($update, $user, PendingStudentUpdate::STATUS_APPROVED, null, 'Approved');
        });

        return response()->json([
            'message' => 'Student profile update approved successfully.',
            'update' => $update->fresh(['student', 'reviewer'])
        ]);
    }

    /**
     * Reject a pending update.
     *
     * Registrar staff only, for the same reason as approve().
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $update = PendingStudentUpdate::with('student')->find($id);

        if (!$update) {
            return response()->json(['message' => 'Pending update not found.'], 404);
        }

        if ($update->status !== PendingStudentUpdate::STATUS_PENDING) {
            return response()->json(['message' => 'This update has already been processed.'], 422);
        }

        $user = $request->user();
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:1000'
        ]);

        DB::transaction(function () use ($update, $user, $validated) {
            $this->decide($update, $user, PendingStudentUpdate::STATUS_REJECTED, $validated['rejection_reason'] ?? null, 'Rejected');
        });

        return response()->json([
            'message' => 'Student profile update rejected.',
            'update' => $update->fresh(['student', 'reviewer'])
        ]);
    }

    /**
     * Return a pending update to the student for correction (#88). The
     * remarks are required: they tell the student what to fix. Registrar
     * staff only, like approve() and reject().
     */
    public function returnForRevision(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $update = PendingStudentUpdate::with('student')->find($id);

        if (!$update) {
            return response()->json(['message' => 'Pending update not found.'], 404);
        }

        if ($update->status !== PendingStudentUpdate::STATUS_PENDING) {
            return response()->json(['message' => 'This update has already been processed.'], 422);
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Write remarks telling the student what to correct.',
        ]);
        $user = $request->user();

        DB::transaction(function () use ($update, $user, $validated) {
            $this->decide($update, $user, PendingStudentUpdate::STATUS_REVISION_REQUIRED, trim($validated['remarks']), 'Returned for revision');
        });

        return response()->json([
            'message' => 'Profile update returned to the student for revision.',
            'update' => $update->fresh(['student', 'reviewer'])
        ]);
    }

    /**
     * Download or view the supporting document.
     */
    public function downloadDocument(Request $request, int $id)
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $update = PendingStudentUpdate::find($id);

        if (!$update || !$update->supporting_document_path) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $path = $update->supporting_document_path;

        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Document file does not exist on server.'], 404);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $path,
            $update->supporting_document_original_name
        );
    }

    /**
     * Records the registrar's decision on the update, in its review history
     * and in the system log, and tells the student in the portal (#89).
     */
    private function decide(PendingStudentUpdate $update, User $user, string $status, ?string $reason, string $verb): void
    {
        $update->fill([
            'status' => $status,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $update->recordHistory($status, $reason);
        $update->save();

        $student = $update->student;
        $studentName = trim($student->first_name . ' ' . $student->last_name);
        $studentNumber = $student->student_number ?? "ID#{$student->student_id}";
        $changedStr = implode(', ', $update->changed_fields ?? []);

        SystemLog::create([
            'action' => "{$verb} profile update for {$studentNumber} ({$studentName}): fields: {$changedStr}",
            'user_id' => $user->id,
            'role' => $user->roles->first()?->name ?? $user->role ?? 'staff',
        ]);

        $this->notifier->profileUpdateChanged($update);
    }
}
