<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2: what a faculty member, program coordinator or dean is assigned to,
 * shown on their portal home. Only their own assignments; no student data.
 */
class AcademicProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'facultyProfile.school:id,code,name',
            'deanOf:id,code,name,dean_user_id',
            'deanOf.programs:id,code,name,school_id',
            'coordinatedPrograms:id,code,name,school_id',
            'coordinatedPrograms.school:id,code,name',
        ]);

        return response()->json([
            'role' => $user->role,
            'role_label' => Roles::LABELS[$user->role] ?? $user->role,
            'name' => $user->name,
            'faculty' => $user->facultyProfile ? [
                'employee_no' => $user->facultyProfile->employee_no,
                'school' => $user->facultyProfile->school,
            ] : null,
            'dean_of' => $user->deanOf,
            'coordinates' => $user->coordinatedPrograms->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'school' => $p->school,
            ])->values(),
        ]);
    }
}
