<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\SystemLog;
use App\Services\Enrollment\EnrollmentTerm;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Curriculum entries: where a subject sits in a program (year level and
 * semester). Placing, moving and removing entries is registrar work (#24).
 *
 * Only `curriculum` and `curriculum_prerequisites` are ever written here;
 * enrollments and grades keep the terms they were recorded against.
 */
class CurriculumEntryController extends Controller
{
    use AuthorizesRole;

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
        $semester = EnrollmentTerm::normaliseSemester($validated['semester']);

        if ($program->archived_at !== null) {
            return $this->refuse('program', "{$program->code} is archived and can't receive new subjects.");
        }

        $subject = Subject::find($validated['subject_id']);
        if ($subject->archived_at !== null) {
            return $this->refuse('subject_id', "{$subject->code} {$subject->title} is archived and can't be placed in a curriculum.");
        }

        // A subject appears once per program; the unique index backs this up.
        $existing = Curriculum::where('program_id', $program->id)->where('subject_id', $subject->id)->first();
        if ($existing) {
            return $this->refuse('subject_id', "{$subject->code} is already in {$program->code}, "
                . $this->termLabel($existing->year_level, $existing->semester) . '.');
        }

        $entry = Curriculum::create([
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'year_level' => $yearLevel,
            'semester'   => (string) $semester,
        ]);

        $this->log($request, "Curriculum: placed {$subject->code} in {$program->code} " . $this->termShort($yearLevel, $semester));

        return response()->json([
            'message' => "{$subject->code} placed in {$program->code}, " . $this->termLabel($yearLevel, $semester) . '.',
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
        $semester = EnrollmentTerm::normaliseSemester($validated['semester']);

        $fromYear = (int) $entry->year_level;
        $fromSemester = (int) $entry->semester;
        $code = $entry->subject->code;
        $programCode = $entry->program->code;

        if ($fromYear === $yearLevel && $fromSemester === $semester) {
            return response()->json([
                'message' => "{$code} is already in " . $this->termLabel($yearLevel, $semester) . '.',
                'entry'   => $this->present($entry),
            ]);
        }

        $entry->update(['year_level' => $yearLevel, 'semester' => (string) $semester]);

        $this->log($request, "Curriculum: moved {$code} in {$programCode} from "
            . $this->termShort($fromYear, $fromSemester) . ' to ' . $this->termShort($yearLevel, $semester));

        return response()->json([
            'message' => "{$code} moved to " . $this->termLabel($yearLevel, $semester) . '.',
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

        // Removing a subject other entries of this program require would leave
        // their prerequisite pointing outside the curriculum.
        $dependents = $this->entriesRequiring($entry);
        if ($dependents->isNotEmpty()) {
            $codes = $dependents->pluck('subject.code');
            $list = $codes->count() > 1
                ? $codes->slice(0, -1)->join(', ') . ' and ' . $codes->last()
                : $codes->first();

            return response()->json([
                'message' => "{$code} can't be removed from {$programCode}: {$list} "
                    . ($codes->count() > 1 ? 'list' : 'lists') . ' it as a prerequisite.',
                'required_by' => $dependents->map(fn (Curriculum $d) => $this->summary($d))->values(),
            ], 409);
        }

        DB::transaction(function () use ($entry) {
            // Its own prerequisite links go with it (also ON DELETE CASCADE).
            $entry->prerequisites()->detach();
            $entry->delete();
        });

        $this->log($request, "Curriculum: removed {$code} from {$programCode} "
            . $this->termShort((int) $entry->year_level, (int) $entry->semester));

        return response()->json(['message' => "{$code} removed from {$programCode}."]);
    }

    /** Year level 1-4 and a semester normaliseSemester() recognises; no silent default. */
    private function termRules(): array
    {
        return [
            'year_level' => ['required', 'integer', 'between:1,4'],
            'semester'   => ['required', function (string $attribute, mixed $value, Closure $fail) {
                if ((! is_int($value) && ! is_string($value)) || EnrollmentTerm::normaliseSemester($value) === null) {
                    $fail('The semester must be 1st or 2nd.');
                }
            }],
        ];
    }

    /**
     * Entries of the same program that list this entry's subject as a
     * prerequisite.
     *
     * @return \Illuminate\Support\Collection<int, Curriculum>
     */
    private function entriesRequiring(Curriculum $entry)
    {
        return Curriculum::with('subject')
            ->where('program_id', $entry->program_id)
            ->whereKeyNot($entry->id)
            ->whereHas('prerequisites', fn ($q) => $q->whereKey($entry->subject_id))
            ->get()
            ->sortBy(fn (Curriculum $c) => [$c->year_level, $c->semester, $c->subject->code])
            ->values();
    }

    /** An entry as the program curriculum endpoint lists it. */
    private function present(Curriculum $entry): Curriculum
    {
        $entry->load(['subject', 'prerequisites:id,code,title']);
        $entry->subject?->append('archived');
        $entry->unsetRelation('program');

        return $entry;
    }

    private function summary(Curriculum $entry): array
    {
        return [
            'entry_id'   => $entry->id,
            'subject_id' => $entry->subject_id,
            'code'       => $entry->subject->code,
            'title'      => $entry->subject->title,
            'year_level' => (int) $entry->year_level,
            'semester'   => (int) $entry->semester,
        ];
    }

    private function refuse(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    /** "Year 1 1st semester" */
    private function termLabel(int|string $yearLevel, int|string $semester): string
    {
        return 'Year ' . (int) $yearLevel . ' ' . ((int) $semester === 1 ? '1st' : '2nd') . ' semester';
    }

    /** "Y1 S2", for the system log. */
    private function termShort(int|string $yearLevel, int|string $semester): string
    {
        return 'Y' . (int) $yearLevel . ' S' . (int) $semester;
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
