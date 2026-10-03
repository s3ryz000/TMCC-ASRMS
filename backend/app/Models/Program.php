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
