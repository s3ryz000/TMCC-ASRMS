<?php

/*
 * TMCC ASRMS deployment settings (#62, #64, #65, #86).
 *
 * Backups hold every student's records: keep ASRMS_BACKUP_PATH on the
 * server's second drive, outside public/ and outside the repository.
 */
return [

    // How long a login lasts (#86). A session ends after idle_minutes without
    // a request, and max_hours after signing in whatever the activity.
    'session' => [
        'idle_minutes' => (int) env('ASRMS_SESSION_IDLE_MINUTES', 60),
        'max_hours' => (int) env('ASRMS_SESSION_MAX_HOURS', 12),
    ],

    'backup' => [
        // Where backup sets are written: daily/, weekly/, monthly/, pre-update/, pre-restore/.
        'path' => env('ASRMS_BACKUP_PATH', storage_path('app/backups')),

        // Uploaded files included in every backup (student documents, profile-update proofs).
        'files' => env('ASRMS_BACKUP_FILES_PATH', storage_path('app/private')),

        // One line per run; the admin's Backups card (#65) reads the stored result.
        'log' => env('ASRMS_BACKUP_LOG', storage_path('logs/backup.log')),

        // MySQL tools (XAMPP on the TMCC server). SQLite needs neither.
        'mysqldump' => env('ASRMS_MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'),
        'mysql' => env('ASRMS_MYSQL_PATH', 'C:\\xampp\\mysql\\bin\\mysql.exe'),

        // Copies kept per folder: 7 daily, 4 weekly, 12 monthly; 10 before each update or restore.
        'keep' => [
            'daily' => 7,
            'weekly' => 4,
            'monthly' => 12,
            'pre-update' => 10,
            'pre-restore' => 10,
        ],

        // The admin card turns red when the last success is older than this.
        'stale_after_hours' => 26,
    ],

];
