<?php

namespace App\Services;

use App\Models\PendingStudentUpdate;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Notifications\StudentPortalNotice;

/**
 * Tells a student in the portal when their document request or profile
 * update changes (#89). Only the student's own portal account is notified,
 * and the text names only their own request.
 */
class StudentNotifier
{
    public const REQUEST_PAGE = '/dashboard/request';

    public const SIS_PAGE = '/dashboard/sis';

    public function recordRequestChanged(RecordRequest $request): void
    {
        $what = 'Your ' . $request->typeLabel() . ' request';

        $message = match ($request->status) {
            RecordRequest::STATUS_APPROVED => "{$what} was approved."
                . ($request->appointment_at ? ' Release: ' . $request->appointment_at->format('D j M Y, g:i A') . '.' : ''),
            RecordRequest::STATUS_REJECTED => "{$what} was rejected."
                . (filled($request->rejection_reason) ? " Reason: {$request->rejection_reason}" : ''),
            RecordRequest::STATUS_RELEASED => "{$what} was released to you.",
            default => null,
        };

        if ($message) {
            $this->notify($request->student, new StudentPortalNotice('record_request', $request->id, $request->status, $message, self::REQUEST_PAGE));
        }
    }

    public function profileUpdateChanged(PendingStudentUpdate $update): void
    {
        $fields = str_replace('_', ' ', implode(', ', $update->changed_fields ?? []));
        $what = 'Your profile update' . ($fields !== '' ? " ({$fields})" : '');

        $message = match ($update->status) {
            PendingStudentUpdate::STATUS_APPROVED => "{$what} was approved.",
            PendingStudentUpdate::STATUS_REJECTED => "{$what} was rejected."
                . (filled($update->rejection_reason) ? " Reason: {$update->rejection_reason}" : ''),
            PendingStudentUpdate::STATUS_REVISION_REQUIRED => "{$what} was returned for revision. Remarks: {$update->rejection_reason}"
                . ' Correct it and resubmit on the SIS page.',
            default => null,
        };

        if ($message) {
            $this->notify($update->student, new StudentPortalNotice('profile_update', $update->id, $update->status, $message, self::SIS_PAGE));
        }
    }

    /** Only a student portal account is notified; a student without one has nobody to tell. */
    private function notify(?Student $student, StudentPortalNotice $notice): void
    {
        $user = $student?->user;
        if ($user && ($user->roles->first()?->name ?? $user->role ?? null) === 'student') {
            $user->notify($notice);
        }
    }
}
