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

    /** Longest first, so "HMPE" would be tried before a shorter "HM". */
    public static function sortForMatching(array $prefixes): array
    {
        usort($prefixes, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $prefixes;
    }

    /**
     * The prefix a subject code belongs to, or null. Codes have no separator
     * since #16 (prefix and number joined: "GEC4", "TPC10"), so a code belongs
     * to P when it starts with P, ignoring case. When several prefixes match,
     * the longest wins.
     *
     * @param  array  $prefixes  as returned by activePrefixes() / sortForMatching()
     */
    public static function matchCode(string $code, array $prefixes): ?string
    {
        $code = Subject::formatCode($code);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, Subject::formatCode($prefix))) {
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
