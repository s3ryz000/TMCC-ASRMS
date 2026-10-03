<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\StoreCurriculumRequest;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SystemLog;
use App\Services\CurriculumTotals;
use App\Support\CurriculumRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * New Curriculum (#70): a program is created together with its curriculum,
 * in one transaction. Every rule is checked before anything is written
 * (StoreCurriculumRequest), so an invalid entry saves nothing. Later edits go
 * through the curriculum entries API (#24, #28).
 */
class CurriculumController extends Controller
{
    use AuthorizesRole;

    /** POST /staff/curriculums (registrar) */
    public function store(StoreCurriculumRequest $request, CurriculumTotals $totals): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $validated = $request->validated();
        $user = $request->user();
        $role = $this->userRole($user);

        [$program, $newSubjects] = DB::transaction(function () use ($validated, $user, $role) {
            $program = Program::create([
                'code'        => $validated['program']['code'],
                'name'        => $validated['program']['name'],
                'description' => $validated['program']['description'] ?? null,
            ]);

            $newSubjects = [];
            foreach ($validated['entries'] as $entry) {
                $subjectId = $entry['subject_id'] ?? null;

                if (isset($entry['new_subject'])) {
                    $subject = Subject::create([
                        'code'        => $entry['new_subject']['code'],
                        'title'       => $entry['new_subject']['title'],
                        'units'       => $entry['new_subject']['units'],
                        'description' => $entry['new_subject']['description'] ?? null,
                    ]);
                    $newSubjects[] = $subject;
                    $subjectId = $subject->id;
                    $this->log($user, $role, "Subject created: {$subject->code} — {$subject->title}");
                }

                Curriculum::create([
                    'program_id' => $program->id,
                    'subject_id' => $subjectId,
                    'year_level' => (int) $entry['year_level'],
                    'semester'   => (string) CurriculumRules::semesterNumber($entry['semester']),
                ]);
            }

            $count = count($validated['entries']);
            $this->log($user, $role, "Curriculum created: {$program->code} with {$count} " . ($count === 1 ? 'subject' : 'subjects'));

            return [$program, $newSubjects];
        });

        $count = count($validated['entries']);

        return response()->json([
            'message'      => "{$program->code} created with {$count} " . ($count === 1 ? 'subject' : 'subjects') . '.',
            'program'      => $program,
            'new_subjects' => collect($newSubjects)->map(fn (Subject $s) => ['id' => $s->id, 'code' => $s->code, 'title' => $s->title])->values(),
            'totals'       => $totals->forProgram($program->id),
        ], 201);
    }

    private function log($user, ?string $role, string $action): void
    {
        SystemLog::create(['action' => $action, 'user_id' => $user->id, 'role' => $role]);
    }
}
