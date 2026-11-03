<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\FacultyProfile;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 2 (TMCC letter, 27 Oct 2026, 1A–1C): the admin (MIS) keeps the
 * academic structure: schools and their deans, which school each program
 * belongs to, program coordinators, and each faculty member's school.
 * Schools are archived, never deleted (#103).
 */
class SchoolController extends Controller
{
    use AuthorizesRole;

    /** Schools with dean and programs, programs not yet in a school, and the academic accounts to choose from. */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $schools = School::with(['dean:id,name,username', 'programs:id,code,name,school_id', 'programs.coordinators:id,name,username'])
            ->withCount('faculty')
            ->orderBy('code')
            ->get();

        $unassigned = Program::with('coordinators:id,name,username')
            ->whereNull('school_id')
            ->whereNull('archived_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'school_id']);

        $people = User::query()
            ->whereIn('role', Roles::ACADEMIC)
            ->where('status', 'active')
            ->with('facultyProfile:id,user_id,school_id,employee_no')
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'role']);

        return response()->json([
            'schools' => $schools,
            'unassigned_programs' => $unassigned,
            'people' => $people,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $data = $request->validate($this->rules());
        $school = School::create($data);

        AuditLog::write("School created: {$school->code} — {$school->name}", $request->user());

        return response()->json(['message' => 'School created.', 'school' => $school->load('dean:id,name,username')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $school = School::find($id);
        if (! $school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $data = $request->validate($this->rules($school->id));
        $school->update($data);

        if ($school->wasChanged()) {
            $dean = $school->dean_user_id ? User::find($school->dean_user_id)?->username : 'none';
            AuditLog::write("School updated: {$school->code} — {$school->name}; dean {$dean}", $request->user());
        }

        return response()->json(['message' => 'School updated.', 'school' => $school->load('dean:id,name,username')]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, true);
    }

    public function unarchive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, false);
    }

    /** Put a program in a school, or take it out (school_id null). */
    public function assignProgram(Request $request, int $programId): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $program = Program::find($programId);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $data = $request->validate([
            'school_id' => ['present', 'nullable', 'integer', Rule::exists('schools', 'id')->whereNull('archived_at')],
        ]);

        $program->forceFill(['school_id' => $data['school_id']])->save();
        $school = $program->school?->code ?? 'none';
        AuditLog::write("Program {$program->code} assigned to school {$school}", $request->user());

        return response()->json(['message' => 'Program updated.', 'program' => $program->load('school:id,code,name')]);
    }

    /** Replace a program's coordinators. Only active coordinator accounts qualify. */
    public function setCoordinators(Request $request, int $programId): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $program = Program::find($programId);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:5'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', Roles::COORDINATOR)->where('status', 'active')],
        ], [
            'user_ids.*.exists' => 'Only active Program Coordinator accounts can coordinate a program.',
        ]);

        $program->coordinators()->sync($data['user_ids']);
        $names = $program->coordinators()->pluck('username')->implode(', ') ?: 'none';
        AuditLog::write("Program {$program->code} coordinators: {$names}", $request->user());

        return response()->json(['message' => 'Coordinators updated.', 'program' => $program->load('coordinators:id,name,username')]);
    }

    /** Set a faculty member's school and employee number. */
    public function setFacultyProfile(Request $request, int $userId): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $user = User::where('id', $userId)->where('role', Roles::FACULTY)->first();
        if (! $user) {
            return response()->json(['message' => 'Faculty account not found.'], 404);
        }

        $profile = FacultyProfile::firstOrNew(['user_id' => $user->id]);
        $data = $request->validate([
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->whereNull('archived_at')],
            'employee_no' => ['nullable', 'string', 'max:30', Rule::unique('faculty_profiles', 'employee_no')->ignore($profile->id)],
        ]);

        $profile->fill($data)->save();
        AuditLog::write("Faculty profile updated: {$user->username}", $request->user());

        return response()->json(['message' => 'Faculty profile saved.', 'profile' => $profile->load('school:id,code,name')]);
    }

    private function rules(?int $schoolId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('schools', 'code')->ignore($schoolId)],
            'name' => ['required', 'string', 'max:150'],
            // The dean must be an active Dean account, and leads only one school.
            'dean_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('role', Roles::DEAN)->where('status', 'active'),
                Rule::unique('schools', 'dean_user_id')->ignore($schoolId),
            ],
        ];
    }

    private function setArchived(Request $request, int $id, bool $archived): JsonResponse
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $school = School::find($id);
        if (! $school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        DB::transaction(function () use ($school, $archived) {
            $school->forceFill(['archived_at' => $archived ? now() : null])->save();
        });
        AuditLog::write(($archived ? 'School archived: ' : 'School restored: ') . $school->code, $request->user());

        return response()->json(['message' => $archived ? 'School archived.' : 'School restored.', 'school' => $school]);
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        return $this->requireAuth() ?? $this->requireRoles($request->user(), [Roles::ADMIN]);
    }
}
