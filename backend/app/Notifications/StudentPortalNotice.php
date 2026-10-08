<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One line in the student portal's notification bell (#89). Stored in the
 * database only (the system runs on a LAN without e-mail). It carries the
 * plain-words message and the portal page it leads to, nothing else.
 */
class StudentPortalNotice extends Notification
{
    /**
     * @param  string  $kind  record_request | profile_update
     * @param  string  $status  the new status of the request or update
     * @param  string  $link  the portal page to open (Request Documents or SIS)
     */
    public function __construct(
        public string $kind,
        public int $subjectId,
        public string $status,
        public string $message,
        public string $link,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'subject_id' => $this->subjectId,
            'status' => $this->status,
            'message' => $this->message,
            'link' => $this->link,
        ];
    }
}
