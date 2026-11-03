<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academic unit of TMCC (e.g. a school of 1–3 programs) led by a dean
 * (Phase 2, TMCC letter 1A).
 */
class School extends Model
{
    protected $fillable = ['code', 'name', 'dean_user_id'];

    /** archived_at is set only through the archive endpoints. */
    protected $casts = ['archived_at' => 'datetime'];

    public function dean(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dean_user_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function faculty(): HasMany
    {
        return $this->hasMany(FacultyProfile::class);
    }
}
