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
     * A subject code in the registrar's format (2 Oct 2026): prefix and number
     * with nothing in between, uppercase. "thc 3", "THC-3" and "THC 3" all
     * become "THC3"; codes without a number ("HRM") are only uppercased.
     */
    public static function formatCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/[\s\p{Pd}]+/u', '', $code));
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
