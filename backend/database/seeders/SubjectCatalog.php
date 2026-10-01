<?php

namespace Database\Seeders;

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
 * Mirrors 2026_10_02_000001_canonicalize_subject_codes, which applied the same
 * map to existing databases. Codes proposed by the team on 2 Oct 2026; to be
 * confirmed with the Registrar.
 */
final class SubjectCatalog
{
    /** "program-local code|title" => [canonical code, canonical title] */
    private const CANONICAL = [
        // GE core
        'GE 1|Understanding the Self'                 => ['GEC-UTS', 'Understanding the Self'],
        'GE 4|Understanding the Self'                 => ['GEC-UTS', 'Understanding the Self'],
        'GE 2|Readings in Philippine History'         => ['GEC-RPH', 'Readings in Philippine History'],
        'GE 3|The Contemporary World'                 => ['GEC-TCW', 'The Contemporary World'],
        'GE 7|The Contemporary World'                 => ['GEC-TCW', 'The Contemporary World'],
        'GE 8|The Contemporary World'                 => ['GEC-TCW', 'The Contemporary World'],
        'GE 4|Mathematics in the Modern World'        => ['GEC-MMW', 'Mathematics in the Modern World'],
        'GE 3|Mathematics in the Modern World'        => ['GEC-MMW', 'Mathematics in the Modern World'],
        'GE 5|Purposive Communication'                => ['GEC-PC', 'Purposive Communication'],
        'GE 1|Purposive Communication'                => ['GEC-PC', 'Purposive Communication'],
        'GE 6|Art Appreciation'                       => ['GEC-AA', 'Art Appreciation'],
        'GE 8|Art Appreciation'                       => ['GEC-AA', 'Art Appreciation'],
        'GE 9|Art Appreciation'                       => ['GEC-AA', 'Art Appreciation'],
        'GE 7|Science, Technology, and Society'       => ['GEC-STS', 'Science, Technology, and Society'],
        'GE 5|Science, Technology, and Society'       => ['GEC-STS', 'Science, Technology, and Society'],
        'GE 6|Science, Technology, and Society'       => ['GEC-STS', 'Science, Technology, and Society'],
        'GE 8|Ethics'                                 => ['GEC-ETH', 'Ethics'],
        'GE 6|Ethics'                                 => ['GEC-ETH', 'Ethics'],
        'GE 7|Ethics'                                 => ['GEC-ETH', 'Ethics'],
        'GE 9|Life and Works of Rizal'                => ['GEC-LWR', 'Life and Works of Rizal'],
        'RIZAL|Life and Works of Rizal'               => ['GEC-LWR', 'Life and Works of Rizal'],
        'GE 5|Indigenous People'                      => ['GEE-IP', 'Indigenous People'],

        // GE electives
        'GE ELECT 1|Gender and Society'               => ['GEE-GS', 'Gender and Society'],
        'GE ELECT 1|Social Science and Philosophy'    => ['GEE-SSP', 'Social Science and Philosophy'],
        'GE ELECT 2|Environmental Science'            => ['GEE-ES', 'Environmental Science'],
        'GE ELECT 2|Arts and Humanities'              => ['GEE-AH', 'Arts and Humanities'],
        'GE ELECT 5|Entrepreneurial Mind'             => ['GEE-EM', 'The Entrepreneurial Mind'],
        'GE ELECT 5|The Entrepreneurial Mind'         => ['GEE-EM', 'The Entrepreneurial Mind'],

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
     * The canonical [code, title] for a program-local code and title, or the
     * pair unchanged when the subject needs no translation.
     *
     * @return array{0: string, 1: string}
     */
    public static function resolve(string $code, string $title): array
    {
        return self::CANONICAL["{$code}|{$title}"] ?? [$code, $title];
    }
}
