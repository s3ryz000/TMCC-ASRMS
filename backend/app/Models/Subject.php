<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['code', 'title', 'units', 'description'];

    /** archived_at is set only through the archive endpoints, never mass-assigned. */
    protected $casts = ['archived_at' => 'datetime'];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function curriculum(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    /**
     * A title reduced to what tells courses apart, so spelling variants of one
     * course compare equal: case, spacing, "&" for "and", "Lab" for
     * "Laboratory" and a leading "The" are ignored.
     */
    public static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = preg_replace('/\s*&\s*/u', ' and ', $title);
        $title = preg_replace('/\blab\b/u', 'laboratory', $title);
        $title = preg_replace('/\s+/u', ' ', trim($title));

        return preg_replace('/^the /u', '', $title);
    }
}
