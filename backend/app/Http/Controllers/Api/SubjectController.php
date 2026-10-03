<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Concerns\ReportsCatalogUsage;
use App\Http\Requests\SaveSubjectRequest;
use App\Models\Subject;
use App\Models\SubjectCodePrefix;
use App\Models\SystemLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * subject cannot be deleted. Archived subjects are left out unless the
     * caller asks for them with include_archived=1 (the catalogue screen).
     *
     * Each subject lists the programs whose curriculum uses it, so the
     * curriculum builder can offer it for reuse (#25). `search` filters by code
     * or title, ignoring case (and spaces or dashes in codes); `per_page` or
     * `page` paginates and adds `meta`. Without them the full list comes back
     * as before.
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $request->validate([
            'include_archived' => ['sometimes', 'boolean'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page'         => ['sometimes', 'integer', 'between:1,100'],
            'page'             => ['sometimes', 'integer', 'min:1'],
        ]);

        $inUse = $this->idsInUse(self::USAGE);
        $prefixes = SubjectCodePrefix::activePrefixes();
        $columns = ['id', 'code', 'title', 'units', 'description', 'archived_at'];

        $query = Subject::withCount(['curriculum', 'grades'])
            ->when(! $request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->when($request->filled('search'), fn ($query) => $this->applySearch($query, (string) $request->input('search')))
            ->orderBy('code')
            ->orderBy('title');

        $paginated = $request->has('per_page') || $request->has('page');
        $page = $paginated
            ? $query->paginate((int) $request->input('per_page', 20), $columns, 'page', (int) $request->input('page', 1))
            : null;
        $subjects = $page ? $page->getCollection() : $query->get($columns);

        $programs = $this->programCodesUsing();

        $body = ['subjects' => $subjects->map(fn (Subject $subject) => [
            'id'               => $subject->id,
            'code'             => $subject->code,
            'title'            => $subject->title,
            'units'            => $subject->units,
            'description'      => $subject->description,
            'curriculum_count' => $subject->curriculum_count,
            'grades_count'     => $subject->grades_count,
            'in_use'           => isset($inUse[$subject->id]),
            'archived'         => $subject->archived_at !== null,
            'prefix'           => SubjectCodePrefix::matchCode($subject->code, $prefixes),
            'programs'         => $programs[$subject->id] ?? [],
        ])->values()];

        if ($page) {
            $body['meta'] = [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ];
        }

        return response()->json($body);
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

        // A subject is shared by every program that places it (#25); the
        // builder warns when an edit reaches more than one.
        return response()->json([
            'message'  => 'Subject updated.',
            'subject'  => $subject,
            'programs' => $this->programCodesUsing()[$subject->id] ?? [],
        ]);
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

    /** Retire a subject that can no longer be deleted; it drops out of the default list. */
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

    /**
     * Code or title contains the search text, ignoring case. Codes are stored
     * in the registrar format, so "gec 4" finds GEC4.
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $like = fn (string $value) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
        $title = mb_strtolower(trim($search));
        $code = Subject::formatCode($search);

        return $query->where(function (Builder $q) use ($like, $title, $code) {
            $q->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$like($title)]);
            if ($code !== '') {
                $q->orWhereRaw("UPPER(code) LIKE ? ESCAPE '!'", [$like($code)]);
            }
        });
    }

    /**
     * The programs whose curriculum places each subject.
     *
     * @return array<int, string[]> subject id => program codes, sorted
     */
    private function programCodesUsing(): array
    {
        return DB::table('curriculum')
            ->join('programs', 'programs.id', '=', 'curriculum.program_id')
            ->distinct()
            ->orderBy('programs.code')
            ->get(['curriculum.subject_id', 'programs.code'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->pluck('code')->all())
            ->all();
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
