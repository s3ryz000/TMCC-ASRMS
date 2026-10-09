<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Controllers\Controller;
use App\Services\UnofficialReportCardService;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student: download their own unofficial report card for a semester, any
 * time (#34). Only the signed-in student's record is ever read; there is no
 * student id in the request to change.
 */
class ReportCardController extends Controller
{
    use AuthorizesRole;

    public function __construct(private readonly UnofficialReportCardService $reportCards)
    {
    }

    /** The semesters the student can download a report card for. */
    public function terms(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        return response()->json(['data' => array_map(
            fn ($t) => $t + ['label' => UnofficialReportCardService::semesterLabel($t['semester']) . ', A.Y. ' . $t['academic_year']],
            $this->reportCards->terms($student),
        )]);
    }

    public function download(Request $request): StreamedResponse|JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:20'],
        ]);

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        if ($this->reportCards->grades($student, $validated['academic_year'], $validated['semester'])->isEmpty()) {
            return response()->json(['message' => 'You have no grades on file for that semester.'], 404);
        }

        $pdf = $this->reportCards->render($student, $validated['academic_year'], $validated['semester']);
        AuditLog::write('Unofficial report card downloaded', $request->user());

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf;
            },
            $this->reportCards->filename($student, $validated['academic_year'], $validated['semester']),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
