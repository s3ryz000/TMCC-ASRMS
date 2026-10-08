<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Services\SystemLogReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /staff/my-activity (#94): the signed-in registrar's own system log
 * rows, read-only, with the admin log's filters except the user (always the
 * caller; a user_id in the request is ignored).
 */
class MyActivityController extends Controller
{
    use AuthorizesRole;

    public function __invoke(Request $request, SystemLogReport $report): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $filters = $request->validate(SystemLogReport::rules(withUser: false));

        $page = $report->query($filters, onlyUserId: $request->user()->id)->paginate(SystemLogReport::perPage($filters));
        $page->getCollection()->transform(fn (SystemLog $log) => $report->present($log));

        return response()->json($page);
    }
}
