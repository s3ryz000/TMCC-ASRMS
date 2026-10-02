<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\Subject;
use App\Models\SubjectCodePrefix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CHED subject code prefixes, for the catalogue's prefix filter (#69).
 * Read-only; staff and admins.
 */
class SubjectCodePrefixController extends Controller
{
    use AuthorizesRole;

    /**
     * Active prefixes ordered by prefix, each with how many subjects (not
     * archived) belong to it.
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $prefixes = SubjectCodePrefix::where('active', true)->orderBy('prefix')->get();
        $matching = SubjectCodePrefix::sortForMatching($prefixes->pluck('prefix')->all());

        $counts = Subject::whereNull('archived_at')
            ->pluck('code')
            ->map(fn (string $code) => SubjectCodePrefix::matchCode($code, $matching))
            ->filter()
            ->countBy();

        return response()->json([
            'prefixes' => $prefixes->map(fn (SubjectCodePrefix $p) => [
                'prefix'         => $p->prefix,
                'full_name'      => $p->full_name,
                'label'          => $p->label(),
                'subjects_count' => $counts[$p->prefix] ?? 0,
            ])->values(),
        ]);
    }
}
