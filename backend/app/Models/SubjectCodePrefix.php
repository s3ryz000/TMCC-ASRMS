<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A CHED subject code prefix such as "TPC" (Tourism Professional Core).
 * Subjects are not linked by id: a subject belongs to the prefix its code
 * starts with, see matchCode().
 */
class SubjectCodePrefix extends Model
{
    protected $fillable = ['prefix', 'full_name', 'active'];

    protected $casts = ['active' => 'boolean'];

    /** The active prefixes, longest first, ready for matchCode(). */
    public static function activePrefixes(): array
    {
        return self::sortForMatching(self::where('active', true)->pluck('prefix')->all());
    }

    /** Longest first, so "GE ELECT" is tried before a shorter "GE". */
    public static function sortForMatching(array $prefixes): array
    {
        usort($prefixes, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $prefixes;
    }

    /**
     * The prefix a subject code belongs to, or null. A code belongs to P when
     * it equals P or starts with "P " or "P-", ignoring case ("PATHFit 1" is
     * PATHFIT). When several prefixes match, the longest wins ("GE ELECT 4" is
     * GE ELECT, not GE).
     *
     * @param  array  $prefixes  as returned by activePrefixes() / sortForMatching()
     */
    public static function matchCode(string $code, array $prefixes): ?string
    {
        $code = mb_strtoupper(trim($code));

        foreach ($prefixes as $prefix) {
            $p = mb_strtoupper($prefix);
            if ($code === $p || str_starts_with($code, $p.' ') || str_starts_with($code, $p.'-')) {
                return $prefix;
            }
        }

        return null;
    }

    public function label(): string
    {
        return "{$this->prefix} - {$this->full_name}";
    }
}
