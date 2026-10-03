<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['code', 'title', 'units', 'description'];

    /** archived_at is set only through the archive endpoints, never mass-assigned. */
    protected $casts = ['archived_at' => 'datetime'];

    /**
     * Whether the subject is archived. Not appended by default; curriculum
     * responses add it with ->append('archived') so they can mark it (#68).
     */
    protected function archived(): Attribute
    {
        return Attribute::get(fn () => $this->archived_at !== null);
    }

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
     * The subject holding this code, compared in the registrar's format
     * (ignoring case, spaces and dashes) so "GEC 4" finds GEC4 and SQLite and
     * MySQL agree.
     */
    public static function withCode(string $code, ?int $exceptId = null): ?self
    {
        return static::query()
            ->whereRaw("UPPER(REPLACE(REPLACE(code, ' ', ''), '-', '')) = ?", [static::formatCode($code)])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
    }

    /** An existing subject whose title is a spelling variant of this one. */
    public static function withSimilarTitle(string $title, ?int $exceptId = null): ?self
    {
        $normalized = static::normalizeTitle($title);

        return static::query()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'code', 'title'])
            ->first(fn (self $subject) => static::normalizeTitle($subject->title) === $normalized);
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
