<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\SaveProgramRequest;
use App\Http\Requests\StoreCurriculumRequest;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SystemLog;
use App\Services\CurriculumTotals;
use App\Support\CurriculumRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $created = [];
            foreach ($validated['entries'] as $index => $entry) {
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

                $created[$index] = Curriculum::create([
                    'program_id'         => $program->id,
                    'subject_id'         => $subjectId,
                    'year_level'         => (int) $entry['year_level'],
                    'semester'           => (string) CurriculumRules::semesterNumber($entry['semester']),
                    'prerequisite_logic' => $entry['prerequisite_logic'] ?? 'AND',
                ]);
            }

            // Prerequisites name other entries by index (#27); their subjects
            // exist now, new ones included.
            foreach ($validated['entries'] as $index => $entry) {
                if (! empty($entry['prerequisites'])) {
                    $created[$index]->prerequisites()->sync(
                        array_map(fn ($i) => $created[(int) $i]->subject_id, $entry['prerequisites'])
                    );
                }
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

    /**
     * POST /staff/programs/{id}/clone {code, name, description?} (registrar, #31)
     *
     * A revised curriculum for a new batch is a new program (#28: no
     * versioning). Every entry, its prerequisite_logic and prerequisites are
     * copied in one transaction. Prerequisites name subjects, so the copied
     * links point at the new program's own entries. Subject rows are shared,
     * not copied; the new program's entries are independent of the source's.
     */
    public function clone(Request $request, int $id, CurriculumTotals $totals): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $source = Program::find($id);
        if (! $source) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $validated = $request->validate(SaveProgramRequest::fieldRules(), ['code.unique' => SaveProgramRequest::CODE_TAKEN]);
        $user = $request->user();
        $role = $this->userRole($user);
        $entries = Curriculum::with(['prerequisites:id', 'subject:id,code,archived_at'])->where('program_id', $source->id)->orderBy('id')->get();

        $program = DB::transaction(function () use ($validated, $source, $entries, $user, $role) {
            $program = Program::create([
                'code'        => $validated['code'],
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            foreach ($entries as $entry) {
                $copy = Curriculum::create([
                    'program_id'               => $program->id,
                    'subject_id'               => $entry->subject_id,
                    'year_level'               => $entry->year_level,
                    'semester'                 => $entry->semester,
                    'prerequisite_logic'       => $entry->prerequisite_logic ?? 'AND',
                    'unresolved_prerequisites' => $entry->unresolved_prerequisites,
                ]);
                $copy->prerequisites()->sync($entry->prerequisites->pluck('id')->all());
            }

            $count = $entries->count();
            $this->log($user, $role, "Curriculum cloned: {$source->code} → {$program->code} ({$count} " . ($count === 1 ? 'subject' : 'subjects') . ')');

            return $program;
        });

        $count = $entries->count();
        $archived = $entries->filter(fn ($e) => $e->subject?->archived_at !== null)->map(fn ($e) => $e->subject->code)->values();

        return response()->json([
            'message'           => "{$program->code} created from {$source->code} with {$count} " . ($count === 1 ? 'subject' : 'subjects') . '.',
            'program'           => $program,
            'source'            => ['id' => $source->id, 'code' => $source->code],
            'entries'           => $count,
            'prerequisites'     => $entries->sum(fn ($e) => $e->prerequisites->count()),
            // Copied as in the source; enrollment refuses them (#68), so the registrar may remove them.
            'archived_subjects' => $archived,
            'totals'            => $totals->forProgram($program->id),
        ], 201);
    }

    private function log($user, ?string $role, string $action): void
    {
        SystemLog::create(['action' => $action, 'user_id' => $user->id, 'role' => $role]);
    }
}
