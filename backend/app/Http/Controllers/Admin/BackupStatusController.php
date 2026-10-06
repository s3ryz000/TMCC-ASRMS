<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/backups/status (admin only, #65): the last backup, the last
 * run, how many copies are kept, free space on the backup drive, and whether
 * the backups need attention (failed, or none in the last 26 hours).
 */
class BackupStatusController extends Controller
{
    use AuthorizesRole;

    public function show(Request $request, BackupService $backups): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        return response()->json($backups->status());
    }
}
