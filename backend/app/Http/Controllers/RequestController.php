<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\RejectRecordRequestRequest;
use App\Models\RecordRequest;
use App\Models\RecordTransaction;
use App\Models\Staff;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\OfficialTranscriptExportService;
use App\Services\QrCodeGenerator;
use App\Services\StaffService;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pending record requests (approve/reject) and document release.
 */
class RequestController extends Controller
{
    use AuthorizesRole;

    private const OFFICE_TIMEZONE = 'Asia/Manila';

    public function __construct(
        private StaffService $staffService
    ) {}

    /**
     * List pending record requests (staff + admin).
     */
    public function indexPending(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $query = RecordRequest::with('student:student_id,student_number,first_name,last_name,email')
            ->where('status', RecordRequest::STATUS_PENDING);

        if ($search = $request->input('search')) {
            $search = preg_replace('/\s+/', ' ', trim($search));
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($recordType = $request->input('record_type')) {
            $query->where('record_type', $recordType);
        }

        $sortKey = $request->input('sort', 'requested_at');
        $sortDir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortKey, $sortDir);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $items = $query->paginate($perPage);

        $items->getCollection()->transform(function (RecordRequest $req) {
            $s = $req->student;
            $req->setAttribute('student_name', $s ? trim($s->first_name . ' ' . $s->last_name) : null);
            return $req;
        });

        return response()->json($items);
    }

    /**
     * Approve a pending request (staff + admin).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $recordRequest = RecordRequest::find($id);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }
        if ($recordRequest->status !== RecordRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Request is not pending.'], 422);
        }

        $staff = $this->staffService->getOrCreateStaffForUser($request->user());
        if (! $staff) {
            return response()->json(['message' => 'Staff record not available.'], 403);
        }
        $validated = $request->validate([
            'appointment_at' => ['required', 'date'],
        ]);

        $appointmentAtOffice = Carbon::parse($validated['appointment_at'])
            ->setTimezone(self::OFFICE_TIMEZONE)
            ->seconds(0);
        if ($appointmentAtOffice->lessThanOrEqualTo(now(self::OFFICE_TIMEZONE))) {
            return response()->json(['message' => 'Please select a future appointment date and time.'], 422);
        }

        // The registrar's office is closed on Sundays (#80).
        if ($appointmentAtOffice->isSunday()) {
            return response()->json(['message' => 'Selected appointment date is not an office day.'], 422);
        }

        if (! in_array($appointmentAtOffice->format('H:i'), $this->availableTimeSlots(), true)) {
            return response()->json(['message' => 'Selected appointment time is not available.'], 422);
        }

        $appointmentAt = $this->toStorageTime($appointmentAtOffice);
        $isTaken = RecordRequest::whereIn('status', [RecordRequest::STATUS_APPROVED, RecordRequest::STATUS_RELEASED])
            ->whereNotNull('appointment_at')
            ->where('appointment_at', $appointmentAt->toDateTimeString())
            ->exists();

        if ($isTaken) {
            return response()->json(['message' => 'Selected appointment slot is already taken.'], 422);
        }

        $recordRequest->update([
            'status' => RecordRequest::STATUS_APPROVED,
            'processed_by' => $staff->staff_id,
            'processed_at' => now(),
            'appointment_at' => $appointmentAt,
            'rejection_reason' => null,
        ]);

        SystemLog::create([
            'action' => 'Request approved',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);

        return response()->json([
            'message' => 'Request approved successfully.',
            'record_request' => $recordRequest->load('student'),
        ]);
    }

    /**
     * Reject a pending request (staff + admin).
     */
    public function reject(RejectRecordRequestRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $recordRequest = RecordRequest::find($id);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }
        if ($recordRequest->status !== RecordRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Request is not pending.'], 422);
        }

        $staff = $this->staffService->getOrCreateStaffForUser($request->user());
        if (! $staff) {
            return response()->json(['message' => 'Staff record not available.'], 403);
        }
        SystemLog::create([
            'action' => 'Request rejected',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        $recordRequest->update([
            'status' => RecordRequest::STATUS_REJECTED,
            'processed_by' => $staff->staff_id,
            'processed_at' => now(),
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        return response()->json([
            'message' => 'Request rejected.',
            'record_request' => $recordRequest->load('student'),
        ]);
    }

    /**
     * List approved requests (for document release tab) (staff + admin).
     */
    public function indexApproved(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $query = RecordRequest::with('student:student_id,student_number,first_name,last_name,email')
            ->where('status', RecordRequest::STATUS_APPROVED);

        $sortKey = $request->input('sort', 'processed_at');
        $sortDir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortKey, $sortDir);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $items = $query->paginate($perPage);

        $items->getCollection()->transform(function (RecordRequest $req) {
            $s = $req->student;
            $req->setAttribute('student_name', $s ? trim($s->first_name . ' ' . $s->last_name) : null);
            return $req;
        });
        SystemLog::create([
            'action' => 'Approved requests listed',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json($items);
    }

    /**
     * List rejected record requests (staff + admin).
     */
    public function indexRejected(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $query = RecordRequest::with('student:student_id,student_number,first_name,last_name,email')
            ->where('status', RecordRequest::STATUS_REJECTED);

        if ($search = $request->input('search')) {
            $search = preg_replace('/\s+/', ' ', trim($search));
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($recordType = $request->input('record_type')) {
            $query->where('record_type', $recordType);
        }

        $sortKey = $request->input('sort', 'processed_at');
        $sortDir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortKey, $sortDir);

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $items = $query->paginate($perPage);

        $items->getCollection()->transform(function (RecordRequest $req) {
            $s = $req->student;
            $req->setAttribute('student_name', $s ? trim($s->first_name . ' ' . $s->last_name) : null);
            return $req;
        });
        SystemLog::create([
            'action' => 'Rejected requests listed',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json($items);
    }

    /**
     * Release document (approved → released) and log transaction (staff + admin).
     */
    public function release(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $recordRequest = RecordRequest::find($id);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }

        $staff = $this->staffService->getOrCreateStaffForUser($request->user());
        if (! $staff) {
            return response()->json(['message' => 'Staff record not available.'], 403);
        }
        return $this->doRelease($recordRequest, $staff, $request->user(), 'Document released');
    }

    /**
     * Create a transaction (e.g. document release)
     */
    public function storeTransaction(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:record_requests,id'],
            'transaction_type' => ['required', 'string', 'in:release'],
        ]);

        $recordRequest = RecordRequest::find($validated['request_id']);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }

        $staff = $this->staffService->getOrCreateStaffForUser($request->user());
        if (! $staff) {
            return response()->json(['message' => 'Staff record not available.'], 403);
        }
        return $this->doRelease($recordRequest, $staff, $request->user(), 'Transaction created');
    }

    public function appointmentSlots(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $monthInput = (string) $request->input('month', now(self::OFFICE_TIMEZONE)->format('Y-m'));
        try {
            $monthStartOffice = Carbon::createFromFormat('Y-m', $monthInput, self::OFFICE_TIMEZONE)->startOfMonth();
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid month format. Use YYYY-MM.'], 422);
        }
        $monthEndOffice = (clone $monthStartOffice)->endOfMonth();
        $monthStart = $this->toStorageTime($monthStartOffice);
        $monthEnd = $this->toStorageTime($monthEndOffice);

        $rows = RecordRequest::query()
            ->whereIn('status', [RecordRequest::STATUS_APPROVED, RecordRequest::STATUS_RELEASED])
            ->whereBetween('appointment_at', [$monthStart->toDateTimeString(), $monthEnd->toDateTimeString()])
            ->whereNotNull('appointment_at')
            ->get(['appointment_at']);

        $takenByDate = [];
        foreach ($rows as $row) {
            if (! $row->appointment_at) {
                continue;
            }
            $apptOffice = $row->appointment_at->copy()->setTimezone(self::OFFICE_TIMEZONE);
            $dateKey = $apptOffice->format('Y-m-d');
            $time = $apptOffice->format('H:i');
            $takenByDate[$dateKey] ??= [];
            if (! in_array($time, $takenByDate[$dateKey], true)) {
                $takenByDate[$dateKey][] = $time;
            }
        }

        return response()->json([
            'month' => $monthStartOffice->format('Y-m'),
            'time_slots' => $this->availableTimeSlots(),
            'taken_by_date' => $takenByDate,
        ]);
    }

    public function downloadTranscriptTemplate(Request $request, int $id): StreamedResponse|JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $recordRequest = RecordRequest::with([
            'student.program',
            'student.grades.subject',
        ])->find($id);

        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }
        if (Str::lower((string) $recordRequest->record_type) !== 'transcript') {
            return response()->json(['message' => 'Template download is only available for transcript requests.'], 422);
        }
        if ($recordRequest->status !== RecordRequest::STATUS_RELEASED) {
            return response()->json(['message' => 'Transcript can only be downloaded after release.'], 422);
        }

        $student = $recordRequest->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found for this request.'], 404);
        }

        SystemLog::create([
            'action' => 'Transcript template downloaded',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return app(OfficialTranscriptExportService::class)->streamForStudent($student);
    }

    public function downloadApprovalSlipStaff(Request $request, int $id): StreamedResponse|JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $recordRequest = RecordRequest::with('student')->find($id);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }
        if (! in_array($recordRequest->status, [RecordRequest::STATUS_APPROVED, RecordRequest::STATUS_RELEASED], true)) {
            return response()->json(['message' => 'Approval slip is only available for approved or released requests.'], 422);
        }

        SystemLog::create([
            'action' => 'Approval slip downloaded',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return $this->buildApprovalSlipPdf($recordRequest);
    }

    public function downloadApprovalSlipStudent(Request $request, int $id): StreamedResponse|JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 403);
        }

        $recordRequest = RecordRequest::with('student')
            ->where('student_id', $student->student_id)
            ->find($id);
        if (! $recordRequest) {
            return response()->json(['message' => 'Record request not found.'], 404);
        }
        if (! in_array($recordRequest->status, [RecordRequest::STATUS_APPROVED, RecordRequest::STATUS_RELEASED], true)) {
            return response()->json(['message' => 'Approval slip is not yet available for this request.'], 422);
        }

        return $this->buildApprovalSlipPdf($recordRequest);
    }

    public function publicAppointmentForm(Request $request, int $id)
    {
        if (! $request->hasValidSignature()) {
            return response('Invalid or expired link.', 403);
        }

        $recordRequest = RecordRequest::with('student')->find($id);
        if (! $recordRequest) {
            return response('Record request not found.', 404);
        }

        $studentName = trim(implode(' ', array_filter([
            $recordRequest->student?->last_name,
            $recordRequest->student?->first_name,
        ])));
        $appointment = $recordRequest->appointment_at
            ? $recordRequest->appointment_at->copy()->timezone(self::OFFICE_TIMEZONE)->format('m/d/Y - h:i A')
            : 'To be scheduled';
        $logoDataUri = $this->resolveSchoolLogoDataUri();
        $status = Str::upper((string) $recordRequest->status);

        $selfUrl = $request->fullUrl();

        // Encoded locally rather than fetched from an image service: the system
        // runs on an isolated campus LAN, so a remote QR URL would render as a
        // broken image on every deployed machine.
        $qrUrl = QrCodeGenerator::pngDataUri($selfUrl);

        $html = '
            <!doctype html>
            <html>
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>Appointment Details</title>
                <style>
                  body { margin: 0; background: #e5e7eb; font-family: Arial, sans-serif; color: #111827; }
                  .wrap { max-width: 720px; margin: 0 auto; padding: 22px 14px 36px; text-align: center; }
                  .logo { width: 44px; height: 44px; object-fit: contain; vertical-align: middle; margin-right: 8px; }
                  .brand { display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; }
                  .school { font-size: 22px; font-weight: 700; margin: 0; letter-spacing: 0.2px; }
                  .sys { font-size: 22px; font-weight: 700; margin: 10px 0 18px; }
                  table { width: 100%; border-collapse: collapse; background: transparent; }
                  td, th { border: 1px solid #9ca3af; padding: 9px 10px; font-size: 20px; background: transparent; }
                  th { width: 46%; text-align: right; font-weight: 600; color: #374151; }
                  td { text-align: left; font-weight: 500; }
                  .qr { margin: 18px auto 0; width: 220px; height: 220px; background: #fff; padding: 6px; }
                </style>
              </head>
              <body>
                <div class="wrap">
                  <div class="brand">' .
                    ($logoDataUri !== '' ? '<img src="' . $logoDataUri . '" alt="School Logo" class="logo" />' : '') . '
                    <h1 class="school">TRECE MARTIRES CITY COLLEGE</h1>
                  </div>
                  <div class="sys">ONLINE APPOINTMENT SYSTEM</div>
                  <table>
                    <tr><th>Reference Number</th><td>' . $this->safeHtml('REQ-' . $recordRequest->id . '-' . $recordRequest->created_at?->format('Ymd')) . '</td></tr>
                    <tr><th>Timestamp</th><td>' . $this->safeHtml($recordRequest->requested_at?->timezone(self::OFFICE_TIMEZONE)->format('Y-m-d H:i:s') ?? 'N/A') . '</td></tr>
                    <tr><th>SRCODE / Application No.</th><td>' . $this->safeHtml((string) ($recordRequest->student?->student_number ?? 'N/A')) . '</td></tr>
                    <tr><th>Fullname</th><td>' . $this->safeHtml($studentName ?: 'N/A') . '</td></tr>
                    <tr><th>Appointment Date</th><td>' . $this->safeHtml($appointment) . '</td></tr>
                    <tr><th>Event</th><td>' . $this->safeHtml((string) $recordRequest->record_type) . '</td></tr>
                    <tr><th>Office</th><td>Registrar&apos;s Office</td></tr>
                    <tr><th>Status</th><td>' . $this->safeHtml($status) . '</td></tr>
                  </table>
                  <img class="qr" src="' . $this->safeHtml($qrUrl) . '" alt="QR Code" />
                </div>
              </body>
            </html>
        ';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Release an approved request. The system log entry is written only once
     * the release has actually happened, so a refused release is never
     * recorded as one.
     */
    private function doRelease(RecordRequest $recordRequest, Staff $staff, User $actor, string $logAction): JsonResponse
    {
        if ($recordRequest->status !== RecordRequest::STATUS_APPROVED) {
            return response()->json(['message' => 'Only approved requests can be released.'], 422);
        }

        $recordRequest->update([
            'status' => RecordRequest::STATUS_RELEASED,
            'released_at' => now(),
        ]);

        RecordTransaction::create([
            'student_id' => $recordRequest->student_id,
            'staff_id' => $staff->staff_id,
            'transaction_type' => 'document_release',
            'transaction_date' => now(),
            'status' => 'completed',
        ]);

        SystemLog::create([
            'action' => $logAction,
            'user_id' => $actor->id,
            'role' => $actor->roles->first()?->name ?? $actor->role ?? null,
        ]);

        return response()->json([
            'message' => 'Document released successfully.',
            'record_request' => $recordRequest->load('student'),
        ]);
    }

    /**
     * Timestamps are stored as wall-clock time in the application timezone,
     * which is how Eloquent reads them back. Appointments used to be written
     * as UTC wall-clock time instead, so a 2:00 PM booking read back as
     * 6:00 AM everywhere it was displayed.
     */
    private function toStorageTime(Carbon $time): Carbon
    {
        return $time->copy()->setTimezone(config('app.timezone'));
    }

    private function availableTimeSlots(): array
    {
        return [
            '08:00', '09:00', '10:00', '11:00',
            '13:00', '14:00', '15:00', '16:00',
        ];
    }

    private function buildApprovalSlipPdf(RecordRequest $recordRequest): StreamedResponse
    {
        $studentName = trim(implode(' ', array_filter([
            $recordRequest->student?->first_name,
            $recordRequest->student?->last_name,
        ])));
        $schoolName = 'TRECE MARTIRES CITY COLLEGE';
        $logoDataUri = $this->resolveSchoolLogoDataUri();

        $appointment = $recordRequest->appointment_at
            ? $recordRequest->appointment_at->copy()->timezone(self::OFFICE_TIMEZONE)->format('F d, Y - h:i A')
            : 'To be scheduled';
        $verificationUrl = URL::temporarySignedRoute(
            'appointment.public.form',
            now()->addDays(30),
            ['id' => $recordRequest->id]
        );
        // Local encoding also means Dompdf never needs remote image fetching
        // enabled to render the slip.
        $qrUrl = QrCodeGenerator::pngDataUri($verificationUrl);

        $html = '
            <html>
              <head>
                <meta charset="utf-8">
                <style>
                  body { font-family: DejaVu Sans, sans-serif; color: #111827; margin: 32px; }
                  .card { border-radius: 8px; padding: 20px; }
                  .header { text-align: center; margin-bottom: 10px; }
                  .logo { width: 60px; height: 60px; object-fit: contain; display: inline-block; margin-bottom: 6px; }
                  .school { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: 0.3px; }
                  .system { font-size: 14px; font-weight: 700; margin: 6px 0 12px; color: #111827; }
                  .title { font-size: 24px; font-weight: 700; margin: 0 0 6px; letter-spacing: 0.4px; }
                  .subtitle { font-size: 12px; color: #4b5563; margin: 0 0 18px; }
                  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
                  th, td { border: 1px solid #d1d5db; padding: 9px 10px; font-size: 12px; }
                  th { width: 36%; text-align: left; }
                  .footer { margin-top: 18px; font-size: 11px; color: #6b7280; }
                  .qr-wrap { margin-top: 14px; text-align: center; }
                  .qr { width: 132px; height: 132px; border: 1px solid #e5e7eb; padding: 4px; background: #fff; }
                  .link { margin-top: 6px; font-size: 10px; word-break: break-all; color: #2563eb; }
                </style>
              </head>
              <body>
                <div class="card">
                  <div class="header">' .
                    ($logoDataUri !== '' ? '<img src="' . $logoDataUri . '" alt="School Logo" class="logo" />' : '') . '
                    <p class="school">' . $this->safeHtml($schoolName) . '</p>
                    <p class="system">ONLINE APPOINTMENT SYSTEM</p>
                  </div>
                  <h1 class="title">Document Request Approval Slip</h1>
                  <p class="subtitle">Record processing reference for scheduled release</p>
                  <table>
                    <tr><th>Reference Number</th><td>' . $this->safeHtml('REQ-' . $recordRequest->id . '-' . $recordRequest->created_at?->format('Ymd')) . '</td></tr>
                    <tr><th>Student Name</th><td>' . $this->safeHtml($studentName ?: 'N/A') . '</td></tr>
                    <tr><th>Record Type</th><td>' . $this->safeHtml((string) $recordRequest->record_type) . '</td></tr>
                    <tr><th>Purpose</th><td>' . $this->safeHtml((string) ($recordRequest->purpose ?? 'N/A')) . '</td></tr>
                    <tr><th>Requested At</th><td>' . $this->safeHtml($recordRequest->requested_at?->format('F d, Y h:i A') ?? 'N/A') . '</td></tr>
                    <tr><th>Approved At</th><td>' . $this->safeHtml($recordRequest->processed_at?->format('F d, Y h:i A') ?? 'N/A') . '</td></tr>
                    <tr><th>Appointment Schedule</th><td>' . $this->safeHtml($appointment) . '</td></tr>
                    <tr><th>Status</th><td>' . $this->safeHtml(Str::upper((string) $recordRequest->status)) . '</td></tr>
                  </table>
                  <div class="qr-wrap">
                    <img src="' . $this->safeHtml($qrUrl) . '" alt="Request QR" class="qr" />
                    <div class="link">' . $this->safeHtml($verificationUrl) . '</div>
                  </div>
                  <p class="footer">Please keep this slip for your release appointment.</p>
                </div>
              </body>
            </html>
        ';

        $options = new Options();
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('request_approval_slip_%d.pdf', $recordRequest->id);
        $pdf = $dompdf->output();

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function safeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function resolveSchoolLogoDataUri(): string
    {
        $candidates = [
            public_path('logo.png'),
            public_path('logo.jpg'),
            base_path('../frontend/public/logo.png'),
            base_path('../frontend/public/logo.jpg'),
        ];

        foreach ($candidates as $path) {
            if (! is_string($path) || ! file_exists($path) || ! is_readable($path)) {
                continue;
            }
            $binary = @file_get_contents($path);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream',
            };

            return 'data:' . $mime . ';base64,' . base64_encode($binary);
        }

        return '';
    }
}
