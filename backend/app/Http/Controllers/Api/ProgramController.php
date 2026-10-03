<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Concerns\ReportsCatalogUsage;
use App\Http\Requests\SaveProgramRequest;
use App\Models\Program;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Degree programs. Reading is open to staff and admins; creating, editing,
 * archiving and deleting programs is registrar work.
 */
class ProgramController extends Controller
{
    use AuthorizesRole;
    use ReportsCatalogUsage;

    /** Everything that refers to a program; any of it blocks a delete (#15). */
    private const USAGE = [
        'student'                  => ['students', 'program_id'],
        'curriculum entry'         => ['curriculum', 'program_id'],
        // No longer written (#21), but historical rows still point at programs.
        'program mapping'          => ['program_mappings', 'program_id'],
        'program change log entry' => ['program_change_logs', ['old_program_id', 'new_program_id']],
    ];

    /**
     * List programs with their usage. Archived programs are left out unless
     * the caller asks for them with include_archived=1.
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $request->validate(['include_archived' => ['sometimes', 'boolean']]);

        $inUse = $this->idsInUse(self::USAGE);

        $programs = Program::withCount(['students', 'curriculum'])
            ->when(! $request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'description', 'archived_at'])
            ->map(fn (Program $program) => [
                'id'               => $program->id,
                'code'             => $program->code,
                'name'             => $program->name,
                'description'      => $program->description,
                'students_count'   => $program->students_count,
                'curriculum_count' => $program->curriculum_count,
                'in_use'           => isset($inUse[$program->id]),
                'archived'         => $program->archived_at !== null,
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

        // The foreign keys restrict deleting a program in use; say what is
        // using it rather than surfacing a database error.
        if ($usage = $this->usageCounts(self::USAGE, $program->id)) {
            return response()->json([
                'message' => "{$program->code} cannot be deleted: used by {$this->describeUsage($usage)}; archive it instead.",
                'usage'   => $usage,
            ], 409);
        }

        $program->delete();

        $this->log($request, "Program deleted: {$program->code} — {$program->name}");

        return response()->json(['message' => 'Program deleted.']);
    }

    /** Retire a program that can no longer be deleted; it drops out of the default list. */
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

        $program = Program::find($id);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $program->forceFill(['archived_at' => $archived ? ($program->archived_at ?? now()) : null])->save();

        $verb = $archived ? 'archived' : 'unarchived';
        $this->log($request, "Program {$verb}: {$program->code} — {$program->name}");

        return response()->json(['message' => "Program {$verb}.", 'program' => $program]);
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
