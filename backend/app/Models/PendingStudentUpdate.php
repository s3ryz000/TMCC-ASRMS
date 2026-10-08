<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's profile change awaiting the registrar: pending → approved |
 * rejected | revision_required; a returned request is corrected and goes back
 * to pending (#88). rejection_reason holds the registrar's latest reason or
 * remarks; review_history keeps every decision and resubmission.
 */
class PendingStudentUpdate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUIRED = 'revision_required';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REVISION_REQUIRED,
    ];

    protected $fillable = [
        'student_id',
        'submitted_by',
        'reviewed_by',
        'status',
        'old_values',
        'new_values',
        'changed_fields',
        'supporting_document_path',
        'supporting_document_original_name',
        'supporting_document_mime',
        'supporting_document_size',
        'rejection_reason',
        'reviewed_at',
        'review_history',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'reviewed_at' => 'datetime',
        'review_history' => 'array',
    ];

    /**
     * The storage path is an internal detail (#58): clients learn only that a
     * document is attached and download it by the update's id.
     */
    protected $hidden = ['supporting_document_path'];

    protected $appends = ['has_supporting_document'];

    public function getHasSupportingDocumentAttribute(): bool
    {
        return (string) $this->supporting_document_path !== '';
    }

    /** Adds one entry (event, remarks, at) to review_history; the caller saves. */
    public function recordHistory(string $event, ?string $remarks = null): void
    {
        $history = $this->review_history ?? [];
        $history[] = ['event' => $event, 'remarks' => $remarks, 'at' => now()->toDateTimeString()];
        $this->review_history = $history;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }
}
