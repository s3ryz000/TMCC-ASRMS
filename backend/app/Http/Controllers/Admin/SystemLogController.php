<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\SystemLogReport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin audit log (#94): newest first, with names and Manila time,
 * filtered by user, role, action text and date range, server-side paged.
 * The PDF export takes the same filters and prints them in its header.
 */
class SystemLogController extends Controller
{
    use AuthorizesRole;

    public function __construct(
        private SystemLogReport $report,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $filters = $request->validate(SystemLogReport::rules());

        $page = $this->report->query($filters)->paginate(SystemLogReport::perPage($filters));
        $page->getCollection()->transform(fn (SystemLog $log) => $this->report->present($log));

        return response()->json($page);
    }

    /** The people who appear in the log, for the user filter. */
    public function users(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $users = User::whereIn('id', SystemLog::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'role']);

        return response()->json(['users' => $users]);
    }

    public function exportPdf(Request $request): StreamedResponse|JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['admin'])) {
            return $err;
        }

        $filters = $request->validate(SystemLogReport::rules());

        $rows = $this->report->query($filters)->get()->map(fn (SystemLog $log) => $this->report->present($log));
        $html = $this->report->pdfHtml($rows, $this->report->describeFilters($filters), $rows->count());

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        $filename  = 'AUDIT_LOG_' . now()->format('Ymd_His') . '.pdf';

        return response()->streamDownload(
            function () use ($pdfOutput) { echo $pdfOutput; },
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }
}
