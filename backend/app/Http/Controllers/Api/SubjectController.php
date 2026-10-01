<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Concerns\ReportsCatalogUsage;
use App\Http\Requests\SaveSubjectRequest;
use App\Models\Subject;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The subject catalogue. Reading is open to staff and admins; creating,
 * editing, archiving and deleting subjects is registrar work.
 */
class SubjectController extends Controller
{
    use AuthorizesRole;
    use ReportsCatalogUsage;

    /** Everything that refers to a subject; any of it blocks a delete (#15). */
    private const USAGE = [
        'curriculum entry'  => ['curriculum', 'subject_id'],
        'prerequisite link' => ['curriculum_prerequisites', 'prerequisite_subject_id'],
        'enrollment'        => ['enrollments', 'subject_id'],
        'grade'             => ['grades', 'subject_id'],
        'audit log entry'   => ['enrollment_audit_logs', 'subject_id'],
    ];

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

        $inUse = $this->idsInUse(self::USAGE);

        $subjects = Subject::withCount(['curriculum', 'grades'])
            ->orderBy('code')
            ->orderBy('title')
            ->get(['id', 'code', 'title', 'units', 'description', 'archived_at'])
            ->map(fn (Subject $subject) => [
                'id'               => $subject->id,
                'code'             => $subject->code,
                'title'            => $subject->title,
                'units'            => $subject->units,
                'description'      => $subject->description,
                'curriculum_count' => $subject->curriculum_count,
                'grades_count'     => $subject->grades_count,
                'in_use'           => isset($inUse[$subject->id]),
                'archived'         => $subject->archived_at !== null,
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

        // The foreign keys restrict deleting a subject in use; say what is
        // using it rather than surfacing a database error.
        if ($usage = $this->usageCounts(self::USAGE, $subject->id)) {
            return response()->json([
                'message' => "{$subject->code} cannot be deleted: used by {$this->describeUsage($usage)}; archive it instead.",
                'usage'   => $usage,
            ], 409);
        }

        $subject->delete();

        $this->log($request, "Subject deleted: {$subject->code} — {$subject->title}");

        return response()->json(['message' => 'Subject deleted.']);
    }

    /** Retire a subject that can no longer be deleted. Nothing filters on it yet. */
    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, true);
    }

    public function unarchive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, false);
    }

    private function setArchived(Request $request, int $id, bool $archived): JsonResponse
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

        $subject->forceFill(['archived_at' => $archived ? ($subject->archived_at ?? now()) : null])->save();

        $verb = $archived ? 'archived' : 'unarchived';
        $this->log($request, "Subject {$verb}: {$subject->code} — {$subject->title}");

        return response()->json(['message' => "Subject {$verb}.", 'subject' => $subject]);
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
