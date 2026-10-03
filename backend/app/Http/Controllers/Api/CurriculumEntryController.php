<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SystemLog;
use App\Services\CurriculumImpact;
use App\Services\CurriculumPrerequisites;
use App\Support\CurriculumRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Curriculum entries: where a subject sits in a program (year level and
 * semester). Placing, moving and removing entries is registrar work (#24).
 *
 * Only `curriculum` and `curriculum_prerequisites` are ever written here;
 * enrollments and grades keep the terms they were recorded against and are
 * never re-validated. An entry that students of the program already have
 * records for is not moved or removed (#28); placing is always allowed.
 */
class CurriculumEntryController extends Controller
{
    use AuthorizesRole;

    public function __construct(private CurriculumImpact $impact)
    {
    }

    /** POST /staff/programs/{programId}/curriculum {subject_id, year_level, semester} */
    public function store(Request $request, int $programId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $program = Program::find($programId);
        if (! $program) {
            return response()->json(['message' => 'Program not found.'], 404);
        }

        $validated = $request->validate([
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            ...$this->termRules(),
        ]);
        $yearLevel = (int) $validated['year_level'];
        $semester = CurriculumRules::semesterNumber($validated['semester']);

        if ($program->archived_at !== null) {
            return $this->refuse('program', "{$program->code} is archived and can't receive new subjects.");
        }

        $subject = Subject::find($validated['subject_id']);
        if ($subject->archived_at !== null) {
            return $this->refuse('subject_id', CurriculumRules::archivedSubject($subject));
        }

        // A subject appears once per program; the unique index backs this up.
        $existing = Curriculum::where('program_id', $program->id)->where('subject_id', $subject->id)->first();
        if ($existing) {
            return $this->refuse('subject_id', CurriculumRules::alreadyPlaced($subject->code, $program->code, $existing->year_level, $existing->semester));
        }

        $entry = Curriculum::create([
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => $yearLevel,
            'semester'   => (string) $semester,
        ]);

        $this->log($request, "Curriculum: placed {$subject->code} in {$program->code} " . CurriculumRules::termShort($yearLevel, $semester));

        return response()->json([
            'message' => "{$subject->code} placed in {$program->code}, " . CurriculumRules::termLabel($yearLevel, $semester) . '.',
            'entry'   => $this->present($entry),
        ], 201);
    }

    /** PATCH /staff/curriculum/{entryId} {year_level, semester} */
    public function update(Request $request, int $entryId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $entry = Curriculum::with(['program', 'subject'])->find($entryId);
        if (! $entry) {
            return response()->json(['message' => 'Curriculum entry not found.'], 404);
        }

        $validated = $request->validate($this->termRules());
        $yearLevel = (int) $validated['year_level'];
        $semester = CurriculumRules::semesterNumber($validated['semester']);

        $fromYear = (int) $entry->year_level;
        $fromSemester = (int) $entry->semester;
        $code = $entry->subject->code;
        $programCode = $entry->program->code;

        if ($fromYear === $yearLevel && $fromSemester === $semester) {
            return response()->json([
                'message' => "{$code} is already in " . CurriculumRules::termLabel($yearLevel, $semester) . '.',
                'entry'   => $this->present($entry),
            ]);
        }

        if ($refusal = $this->refuseIfHistory($entry)) {
            return $refusal;
        }

        $entry->update(['year_level' => $yearLevel, 'semester' => (string) $semester]);

        $this->log($request, "Curriculum: moved {$code} in {$programCode} from "
            . CurriculumRules::termShort($fromYear, $fromSemester) . ' to ' . CurriculumRules::termShort($yearLevel, $semester));

        return response()->json([
            'message' => "{$code} moved to " . CurriculumRules::termLabel($yearLevel, $semester) . '.',
            'entry'   => $this->present($entry),
        ]);
    }

    /** DELETE /staff/curriculum/{entryId} */
    public function destroy(Request $request, int $entryId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $entry = Curriculum::with(['program', 'subject'])->find($entryId);
        if (! $entry) {
            return response()->json(['message' => 'Curriculum entry not found.'], 404);
        }

        $code = $entry->subject->code;
        $programCode = $entry->program->code;

        if ($refusal = $this->refuseIfHistory($entry)) {
            return $refusal;
        }

        // Removing a subject other entries of this program require would leave
        // their prerequisite pointing outside the curriculum.
        $dependents = $this->impact->entriesRequiring($entry);
        if ($dependents->isNotEmpty()) {
            $codes = $dependents->pluck('subject.code');
            $list = $codes->count() > 1
                ? $codes->slice(0, -1)->join(', ') . ' and ' . $codes->last()
                : $codes->first();

            return response()->json([
                'message' => "{$code} can't be removed from {$programCode}: {$list} "
                    . ($codes->count() > 1 ? 'list' : 'lists') . ' it as a prerequisite.',
                'required_by' => $dependents->map(fn (Curriculum $d) => CurriculumImpact::summary($d))->values(),
            ], 409);
        }

        DB::transaction(function () use ($entry) {
            // Its own prerequisite links go with it (also ON DELETE CASCADE).
            $entry->prerequisites()->detach();
            $entry->delete();
        });

        $this->log($request, "Curriculum: removed {$code} from {$programCode} "
            . CurriculumRules::termShort((int) $entry->year_level, (int) $entry->semester));

        return response()->json(['message' => "{$code} removed from {$programCode}."]);
    }

    /**
     * GET /staff/curriculum/{entryId}/impact (staff and admin): the students
     * of this program with records in the subject and the entries that
     * require it, so the builder can say why a move or removal is refused.
     */
    public function impact(Request $request, int $entryId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $entry = Curriculum::with(['program', 'subject'])->find($entryId);
        if (! $entry) {
            return response()->json(['message' => 'Curriculum entry not found.'], 404);
        }

        return response()->json($this->impact->report($entry));
    }

    /**
     * PUT /staff/curriculum/{entryId}/prerequisites {subject_ids: [...], logic: AND|OR}
     *
     * Replaces the entry's prerequisites (an empty list clears them). Allowed
     * even when students have records: it applies to future enrollments only,
     * and the screen shows the /impact count first (#28).
     */
    public function prerequisites(Request $request, int $entryId, CurriculumPrerequisites $prerequisites): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $entry = Curriculum::with(['program', 'subject'])->find($entryId);
        if (! $entry) {
            return response()->json(['message' => 'Curriculum entry not found.'], 404);
        }

        if (is_string($request->input('logic'))) {
            $request->merge(['logic' => strtoupper(trim($request->input('logic')))]);
        }
        $validated = $request->validate([
            'subject_ids'   => ['present', 'array', 'max:20'],
            'subject_ids.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')],
            'logic'         => ['nullable', Rule::in(CurriculumPrerequisites::LOGIC)],
        ], [
            'subject_ids.*.exists'   => 'This subject does not exist.',
            'subject_ids.*.distinct' => 'This subject is listed twice.',
            'logic.in'               => 'The logic must be AND or OR.',
        ]);
        $subjectIds = array_map('intval', $validated['subject_ids']);
        $logic = $validated['logic'] ?? 'AND';

        if ($problems = $prerequisites->problems($entry, $subjectIds)) {
            return response()->json([
                'message' => reset($problems),
                'errors'  => array_map(fn ($message) => [$message], $problems),
            ], 422);
        }

        $prerequisites->save($entry, $subjectIds, $logic);

        $codes = Subject::whereIn('id', $subjectIds)->pluck('code')->sort(SORT_NATURAL)->values();
        $this->log($request, CurriculumPrerequisites::logMessage($entry->program->code, $entry->subject->code, $codes, $logic));

        return response()->json([
            'message' => $codes->isEmpty()
                ? "{$entry->subject->code} has no prerequisites now."
                : "{$entry->subject->code} now requires " . $codes->join($logic === 'OR' ? ' or ' : ' and ') . '.',
            'entry'   => $this->present($entry->fresh(['program', 'subject'])),
        ]);
    }

    /**
     * Curriculum changes never rewrite student history (#28): an entry whose
     * subject has an enrollment or grade from a student of this program is
     * not moved or removed.
     */
    private function refuseIfHistory(Curriculum $entry): ?JsonResponse
    {
        $message = $this->impact->historyBlock($entry);

        return $message === null ? null : response()->json([
            'message' => $message,
            'impact'  => $this->impact->report($entry),
        ], 409);
    }

    /** Year level 1-4 and a semester normaliseSemester() recognises; no silent default. */
    private function termRules(): array
    {
        return [
            'year_level' => CurriculumRules::YEAR_LEVEL,
            'semester'   => CurriculumRules::semester(),
        ];
    }

    /** An entry as the program curriculum endpoint lists it. */
    private function present(Curriculum $entry): Curriculum
    {
        $entry->load(['subject', 'prerequisites:id,code,title']);
        $entry->subject?->append('archived');
        $entry->unsetRelation('program');

        return $entry;
    }

    private function refuse(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
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
