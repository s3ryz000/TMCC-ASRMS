<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\SaveProgramRequest;
use App\Models\Program;
use App\Models\ProgramChangeLog;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Degree programs. Reading is open to staff and admins; creating, editing and
 * deleting programs is registrar work.
 */
class ProgramController extends Controller
{
    use AuthorizesRole;

    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $programs = Program::withCount(['students', 'curriculum'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'description'])
            ->map(fn (Program $program) => [
                'id'               => $program->id,
                'code'             => $program->code,
                'name'             => $program->name,
                'description'      => $program->description,
                'students_count'   => $program->students_count,
                'curriculum_count' => $program->curriculum_count,
                'in_use'           => $this->usageReason($program) !== null,
            ]);

        return response()->json(['programs' => $programs]);
    }

    public function store(SaveProgramRequest $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $program = Program::create($request->validated());

        $this->log($request, "Program created: {$program->code} — {$program->name}");

        return response()->json(['message' => 'Program created.', 'program' => $program], 201);
    }

    public function update(SaveProgramRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $program = Program::find($id);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $program->update($request->validated());

        $this->log($request, "Program updated: {$program->code} — {$program->name}");

        return response()->json(['message' => 'Program updated.', 'program' => $program]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $program = Program::find($id);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        // Deleting cascades to the curriculum and program-change history and
        // detaches students, so only an unused program may go.
        if ($reason = $this->usageReason($program)) {
            return response()->json(['message' => "{$program->code} cannot be deleted: {$reason}."], 422);
        }

        $program->delete();

        $this->log($request, "Program deleted: {$program->code} — {$program->name}");

        return response()->json(['message' => 'Program deleted.']);
    }

    /** Why the program is in use, or null when it is safe to delete. */
    private function usageReason(Program $program): ?string
    {
        if ($program->students_count ?? $program->students()->exists()) {
            return 'students are enrolled in it';
        }
        if ($program->curriculum_count ?? $program->curriculum()->exists()) {
            return 'it has a curriculum';
        }
        if ($program->programMappings()->exists()
            || ProgramChangeLog::where('old_program_id', $program->id)->orWhere('new_program_id', $program->id)->exists()) {
            return 'it appears in student program history';
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
