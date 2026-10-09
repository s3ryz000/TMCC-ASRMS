<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record request lifecycle: pending → approved|rejected; approved → released.
 */
class RecordRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RELEASED = 'released';

    /** The document names students see in the portal. */
    public const TYPE_LABELS = [
        'transcript' => 'Transcript of Records',
        'certificate_of_grades' => 'Certificate of Grades',
        'copy_of_grades' => 'Copy of Grades',
        'deans_list_certificate' => "Dean's List Certificate",
        'presidents_list_certificate' => "President's List Certificate",
        'latin_honor_certificate' => 'Latin Honor Certificate',
    ];

    protected $fillable = [
        'student_id',
        'record_type',
        'academic_year',
        'semester',
        'award_name',
        'purpose',
        'copies',
        'status',
        'requested_at',
        'processed_by',
        'processed_at',
        'appointment_at',
        'released_at',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'appointment_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * Where the document stands for the student (#44): an approved request is
     * ready for pick-up at the Registrar's Office; there is no appointment.
     */
    public function pickupLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => "Ready for pick-up at the Registrar's Office",
            self::STATUS_RELEASED => 'Released' . ($this->released_at ? ' on ' . $this->released_at->format('F d, Y') : ''),
            self::STATUS_REJECTED => 'Rejected',
            default => 'Pending review',
        };
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->record_type] ?? ucwords(str_replace('_', ' ', (string) $this->record_type));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'processed_by', 'staff_id');
    }
}
