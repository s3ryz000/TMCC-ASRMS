<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The school a faculty member belongs to, and their employee number (Phase 2, TMCC letter 1C). */
class FacultyProfile extends Model
{
    protected $fillable = ['user_id', 'school_id', 'employee_no'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
