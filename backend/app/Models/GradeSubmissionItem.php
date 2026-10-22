<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One proposed grade change in a submission, with the grade's values when it was submitted. */
class GradeSubmissionItem extends Model
{
    protected $fillable = [
        'grade_submission_id',
        'grade_id',
        'grade_value',
        'status',
        'remarks',
        'supporting_document_reference',
        'old_value',
    ];

    protected $casts = [
        'grade_value' => 'decimal:2',
        'old_value' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(GradeSubmission::class, 'grade_submission_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }
}
