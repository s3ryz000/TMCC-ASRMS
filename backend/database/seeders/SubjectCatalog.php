<?php

namespace Database\Seeders;

use App\Models\Subject;

/**
 * The canonical code and title of every subject whose curriculum document
 * spells it differently from program to program (#16).
 *
 * The program seeders keep the codes and titles exactly as their CHED
 * curriculum documents print them, because their prerequisite lists refer to
 * those program-local codes (BSHM's "GE 5" is Indigenous People, BSTM's is
 * Science, Technology, and Society). CurriculumSeederHelper passes every row
 * through resolve() before creating the subject, so all programs share one row
 * per course and every code is unique.
 *
 * Mirrors 2026_10_02_000001_canonicalize_subject_codes and
 * 2026_10_03_000002_drop_separators_from_subject_codes, which applied the
 * same result to existing databases: the registrar's GE numbering (GEC1-9,
 * GEE1-8) and codes without spaces or dashes (2 Oct 2026).
 */
final class SubjectCatalog
{
    /** "program-local code|title" => [canonical code, canonical title] */
    private const CANONICAL = [
        // GE core
        'GE 1|Understanding the Self'                 => ['GEC8', 'Understanding the Self'],
        'GE 4|Understanding the Self'                 => ['GEC8', 'Understanding the Self'],
        'GE 2|Readings in Philippine History'         => ['GEC5', 'Readings in Philippine History'],
        'GE 3|The Contemporary World'                 => ['GEC7', 'The Contemporary World'],
        'GE 7|The Contemporary World'                 => ['GEC7', 'The Contemporary World'],
        'GE 8|The Contemporary World'                 => ['GEC7', 'The Contemporary World'],
        'GE 4|Mathematics in the Modern World'        => ['GEC3', 'Mathematics in the Modern World'],
        'GE 3|Mathematics in the Modern World'        => ['GEC3', 'Mathematics in the Modern World'],
        'GE 5|Purposive Communication'                => ['GEC4', 'Purposive Communication'],
        'GE 1|Purposive Communication'                => ['GEC4', 'Purposive Communication'],
        'GE 6|Art Appreciation'                       => ['GEC9', 'Art Appreciation'],
        'GE 8|Art Appreciation'                       => ['GEC9', 'Art Appreciation'],
        'GE 9|Art Appreciation'                       => ['GEC9', 'Art Appreciation'],
        'GE 7|Science, Technology, and Society'       => ['GEC6', 'Science, Technology, and Society'],
        'GE 5|Science, Technology, and Society'       => ['GEC6', 'Science, Technology, and Society'],
        'GE 6|Science, Technology, and Society'       => ['GEC6', 'Science, Technology, and Society'],
        'GE 8|Ethics'                                 => ['GEC1', 'Ethics'],
        'GE 6|Ethics'                                 => ['GEC1', 'Ethics'],
        'GE 7|Ethics'                                 => ['GEC1', 'Ethics'],
        'GE 9|Life and Works of Rizal'                => ['GEC2', 'Life and Works of Rizal'],
        'RIZAL|Life and Works of Rizal'               => ['GEC2', 'Life and Works of Rizal'],
        'GE 5|Indigenous People'                      => ['GEE5', 'Indigenous People'],

        // GE electives
        'GE ELECT 1|Gender and Society'               => ['GEE4', 'Gender and Society'],
        'GE ELECT 1|Social Science and Philosophy'    => ['GEE6', 'Social Science and Philosophy'],
        'GE ELECT 2|Environmental Science'            => ['GEE3', 'Environmental Science'],
        'GE ELECT 2|Arts and Humanities'              => ['GEE1', 'Arts and Humanities'],
        'GE ELECT 5|Entrepreneurial Mind'             => ['GEE2', 'The Entrepreneurial Mind'],
        'GE ELECT 5|The Entrepreneurial Mind'         => ['GEE2', 'The Entrepreneurial Mind'],
        'GE ELECT 3|PEACE Education'                  => ['GEE7', 'PEACE Education'],
        'GE ELECT 4|Living in the IT Era'             => ['GEE8', 'Living in the IT Era'],

        // Program subjects spelled two ways
        'BME 1|Operations Management in TH Industry'  => ['BME 1', 'Operations Management in Tourism and Hospitality Industry'],
        'BME 2|Strategic Management in Tourism and Hospitality 1' => ['BME 2', 'Strategic Management in Tourism and Hospitality'],
        'HMPE 2|Bar and Beverage Management with Lab' => ['HMPE 2', 'Bar and Beverage Management with Laboratory'],
        'HMPE 4|Housekeeping Operation'               => ['HMPE 4', 'Housekeeping Operations'],
        'THC 3|Tourism and Hospitality Service Quality Management' => ['THC 3', 'Quality Service Management in Tourism and Hospitality'],
        'THC 5|Tourism and Hospitality 2 (Micro Perspective of Tourism and Hospitality)' => ['THC 5', 'Micro Perspective of Tourism and Hospitality'],
        'THC 9|Multicultural Diversity in Workplace for the Tourism Professional' => ['THC 9', 'Multicultural Diversity in the Workplace for the Tourism Professional'],

        // Different courses that shared a code: BSTM's moves to TMPE 1.
        'HMPE 1|Recreation and Leisure Management'    => ['TMPE 1', 'Recreation and Leisure Management'],
    ];

    /**
     * The canonical [code, title] for a program-local code and title, with the
     * code in the registrar's format (no spaces or dashes: "THC 3" -> "THC3").
     *
     * @return array{0: string, 1: string}
     */
    public static function resolve(string $code, string $title): array
    {
        [$canonicalCode, $canonicalTitle] = self::CANONICAL["{$code}|{$title}"] ?? [$code, $title];

        return [Subject::formatCode($canonicalCode), $canonicalTitle];
    }
}
