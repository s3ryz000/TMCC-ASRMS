<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\User;
use App\Services\GradeSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grade approvals (#107). Registrar staff with the "approve-grades"
 * permission list, open, approve or return pending grade submissions; the
 * encoder may cancel their own while it is pending. Admins and students are
 * refused by the route's role:staff.
 */
class GradeSubmissionController extends Controller
{
    use AuthorizesRole;

    public function __construct(private GradeSubmissionService $submissions)
    {
    }

    /** GET /api/staff/grade-submissions?status=pending */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireApprover($request->user())) {
            return $err;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:' . implode(',', GradeSubmission::STATUSES)],
        ]);

        $rows = GradeSubmission::with(['student:student_id,student_number,first_name,last_name,program_id', 'student.program:id,code,name', 'submitter:id,name', 'decider:id,name'])
            ->withCount('items')
            ->where('status', $validated['status'] ?? GradeSubmission::STATUS_PENDING)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GradeSubmission $s) => $this->summary($s));

        return response()->json(['data' => $rows]);
    }

    /** GET /api/staff/grade-submissions/{id}: the items, old → new. */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireApprover($request->user())) {
            return $err;
        }

        $submission = GradeSubmission::with(['student:student_id,student_number,first_name,last_name,program_id', 'student.program:id,code,name', 'submitter:id,name', 'decider:id,name', 'items'])
            ->withCount('items')
            ->find($id);
        if (! $submission) {
            return response()->json(['message' => 'Grade submission not found.'], 404);
        }

        $grades = Grade::with('subject:id,code,title,units')
            ->whereIn('id', $submission->items->pluck('grade_id'))
            ->get()
            ->keyBy('id');

        $items = $submission->items->map(function ($item) use ($grades) {
            $grade = $grades->get($item->grade_id);
            $old = $item->old_value ?? [];

            return [
                'id'            => $item->id,
                'grade_id'      => $item->grade_id,
                'subject_code'  => $grade?->subject?->code,
                'subject_title' => $grade?->subject?->title,
                'units'         => $grade?->subject?->units,
                'old'           => [
                    'grade_value' => $old['grade_value'] ?? null,
                    'status'      => $old['status'] ?? null,
                    'remarks'     => $old['remarks'] ?? null,
                ],
                'new'           => [
                    'grade_value' => $item->grade_value,
                    'status'      => $grade ? $this->proposedStatus($grade, $item) : $item->status,
                    'remarks'     => $item->remarks,
                ],
                'supporting_document_reference' => $item->supporting_document_reference,
            ];
        })->values();

        return response()->json(['data' => $this->summary($submission) + ['items' => $items]]);
    }

    /** PATCH /api/staff/grade-submissions/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($err = $this->requireApprover($user)) {
            return $err;
        }
        $submission = GradeSubmission::find($id);
        if (! $submission) {
            return response()->json(['message' => 'Grade submission not found.'], 404);
        }
        if ($err = $this->requirePending($submission)) {
            return $err;
        }
        if ((int) $submission->submitted_by === (int) $user->id) {
            return response()->json(['message' => "You can't approve grades you encoded."], 403);
        }

        if ($error = $this->submissions->approve($submission, $user, $this->userRole($user))) {
            return response()->json(['message' => $error, 'errors' => ['validation' => [$error]]], 422);
        }

        return response()->json([
            'message'    => $submission->items()->count() . ' grade(s) approved.',
            'submission' => $this->summary($submission->fresh(['student.program', 'submitter', 'decider'])->loadCount('items')),
        ]);
    }

    /** PATCH /api/staff/grade-submissions/{id}/return: back to the encoder with remarks. */
    public function returnToEncoder(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($err = $this->requireApprover($user)) {
            return $err;
        }
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],
        ], [
            'remarks.required' => 'Say what needs to be corrected.',
        ]);
        $submission = GradeSubmission::find($id);
        if (! $submission) {
            return response()->json(['message' => 'Grade submission not found.'], 404);
        }
        if ($err = $this->requirePending($submission)) {
            return $err;
        }

        $this->submissions->returnToEncoder($submission, $user, trim($validated['remarks']));

        return response()->json([
            'message'    => 'Grades returned to the encoder.',
            'submission' => $this->summary($submission->fresh(['student.program', 'submitter', 'decider'])->loadCount('items')),
        ]);
    }

    /** PATCH /api/staff/grade-submissions/{id}/cancel: the encoder withdraws a pending submission. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        $user = $request->user();
        if ($err = $this->requireRoles($user, ['staff'])) {
            return $err;
        }
        $submission = GradeSubmission::find($id);
        if (! $submission) {
            return response()->json(['message' => 'Grade submission not found.'], 404);
        }
        if ((int) $submission->submitted_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the staff member who encoded these grades can cancel them.'], 403);
        }
        if ($err = $this->requirePending($submission)) {
            return $err;
        }

        $this->submissions->cancel($submission, $user);

        return response()->json(['message' => 'Grade submission cancelled.']);
    }

    private function requireApprover(?User $user): ?JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($user, ['staff'])) {
            return $err;
        }
        if (! $user->can_approve_grades) {
            return response()->json(['message' => "You don't have access to grade approvals."], 403);
        }

        return null;
    }

    private function requirePending(GradeSubmission $submission): ?JsonResponse
    {
        if ($submission->status !== GradeSubmission::STATUS_PENDING) {
            return response()->json(['message' => "This submission is already {$submission->status}."], 409);
        }

        return null;
    }

    /** The status the item will save, derived the same way approval does. */
    private function proposedStatus(Grade $grade, $item): string
    {
        return app(\App\Services\GradeChangeService::class)->resolve($grade, [
            'grade_value' => $item->grade_value,
            'status'      => $item->status,
            'remarks'     => $item->remarks,
        ])['status'];
    }

    private function summary(GradeSubmission $s): array
    {
        $student = $s->student;

        return [
            'id'             => $s->id,
            'status'         => $s->status,
            'step'           => $s->step,
            'student_id'     => $s->student_id,
            'student_number' => $student?->student_number,
            'student_name'   => trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')),
            'program'        => $student?->program?->code,
            'academic_year'  => $s->academic_year,
            'semester'       => $s->semester,
            'submitted_by'   => ['id' => $s->submitted_by, 'name' => $s->submitter?->name],
            'submitted_at'   => $s->submitted_at?->toIso8601String(),
            'decided_by'     => $s->decided_by ? ['id' => $s->decided_by, 'name' => $s->decider?->name] : null,
            'decided_at'     => $s->decided_at?->toIso8601String(),
            'remarks'        => $s->remarks,
            'item_count'     => $s->items_count ?? $s->items()->count(),
        ];
    }
}
