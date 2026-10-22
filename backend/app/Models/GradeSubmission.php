<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of encoded grades for one student and term, waiting for approval
 * (#107). The grades table only changes when the submission is approved.
 */
class GradeSubmission extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_RETURNED, self::STATUS_CANCELLED];

    /** The registrar's approval; earlier steps (faculty, dean) can be added in front (#109). */
    public const STEP_REGISTRAR = 'registrar';

    protected $fillable = [
        'student_id',
        'academic_year',
        'semester',
        'status',
        'step',
        'submitted_by',
        'submitted_at',
        'decided_by',
        'decided_at',
        'remarks',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GradeSubmissionItem::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
