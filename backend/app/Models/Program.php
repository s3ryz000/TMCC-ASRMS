<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $table = 'programs';

    protected $fillable = ['code', 'name', 'description'];

    /** archived_at is set only through the archive endpoints, never mass-assigned. */
    protected $casts = ['archived_at' => 'datetime'];

    /** Phase 2: the school the program belongs to (set by the admin, not mass-assigned). */
    public function school(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Phase 2: the program's coordinators, if any. */
    public function coordinators(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'program_coordinators')->withTimestamps();
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'program_id');
    }

    public function curriculum(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    /** @deprecated Read-only history; see ProgramMapping (#21). */
    public function programMappings()
    {
        return $this->hasMany(ProgramMapping::class);
    }
}
