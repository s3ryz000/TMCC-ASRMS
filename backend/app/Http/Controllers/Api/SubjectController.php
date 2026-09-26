<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\SaveSubjectRequest;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The subject catalogue. Reading is open to staff and admins; creating,
 * editing and deleting subjects is registrar work.
 */
class SubjectController extends Controller
{
    use AuthorizesRole;

    /**
     * List subjects, with how widely each is used so the UI can explain why a
     * subject cannot be deleted.
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $subjects = Subject::withCount(['curriculum', 'grades'])
            ->orderBy('code')
            ->orderBy('title')
            ->get(['id', 'code', 'title', 'units', 'description'])
            ->map(fn (Subject $subject) => [
                'id'               => $subject->id,
                'code'             => $subject->code,
                'title'            => $subject->title,
                'units'            => $subject->units,
                'description'      => $subject->description,
                'curriculum_count' => $subject->curriculum_count,
                'grades_count'     => $subject->grades_count,
                'in_use'           => $this->usageReason($subject) !== null,
            ]);

        return response()->json(['subjects' => $subjects]);
    }

    public function store(SaveSubjectRequest $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $subject = Subject::create($request->validated());

        $this->log($request, "Subject created: {$subject->code} — {$subject->title}");

        return response()->json(['message' => 'Subject created.', 'subject' => $subject], 201);
    }

    public function update(SaveSubjectRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $subject = Subject::find($id);
        if (! $subject) {
            return response()->json(['message' => 'Subject not found.'], 404);
        }

        $validated = $request->validated();

        // Every GWA is weighted by units, so changing them once grades exist
        // would silently change the standing of every student who took it.
        if ((int) $validated['units'] !== (int) $subject->units && $subject->grades()->exists()) {
            return response()->json([
                'message' => 'Units cannot be changed once grades have been recorded for this subject; it would change those students\' GWA.',
                'errors'  => ['units' => ['Units are locked because grades exist for this subject.']],
            ], 422);
        }

        $subject->update($validated);

        $this->log($request, "Subject updated: {$subject->code} — {$subject->title}");

        return response()->json(['message' => 'Subject updated.', 'subject' => $subject]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $subject = Subject::find($id);
        if (! $subject) {
            return response()->json(['message' => 'Subject not found.'], 404);
        }

        // The foreign keys cascade, so deleting a subject in use would also
        // delete its grades, enrollments and curriculum entries.
        if ($reason = $this->usageReason($subject)) {
            return response()->json(['message' => "{$subject->code} cannot be deleted: {$reason}."], 422);
        }

        $subject->delete();

        $this->log($request, "Subject deleted: {$subject->code} — {$subject->title}");

        return response()->json(['message' => 'Subject deleted.']);
    }

    /** Why the subject is in use, or null when it is safe to delete. */
    private function usageReason(Subject $subject): ?string
    {
        if ($subject->curriculum_count ?? $subject->curriculum()->exists()) {
            return 'it is part of a program curriculum';
        }
        if ($subject->grades_count ?? $subject->grades()->exists()) {
            return 'grades have been recorded for it';
        }
        if ($subject->enrollments()->exists()) {
            return 'students have been enrolled in it';
        }
        if (DB::table('curriculum_prerequisites')->where('prerequisite_subject_id', $subject->id)->exists()
            || Curriculum::where('prerequisite', $subject->id)->exists()) {
            return 'it is a prerequisite of another subject';
        }

        return null;
    }

    private function log(Request $request, string $action): void
    {
        SystemLog::create([
            'action'  => $action,
            'user_id' => $request->user()->id,
            'role'    => $this->userRole($request->user()),
        ]);
    }
}
