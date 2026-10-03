<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Credit-unit totals of a program's curriculum, per term, per year and for
 * the whole program (#26).
 *
 * Always computed from subjects.units at request time and never stored, so a
 * placement, move or removal shows up immediately. Archived subjects still
 * count: they stay in the prospectus (#68), as on the curriculum page.
 */
class CurriculumTotals
{
    /**
     * @return array{
     *     maximum_units: int,
     *     terms: list<array{year_level: int, semester: int, units: int, subjects: int, over_max: bool}>,
     *     years: list<array{year_level: int, units: int, subjects: int}>,
     *     program: array{units: int, subjects: int}
     * }
     */
    public function forProgram(int $programId): array
    {
        $terms = DB::table('curriculum')
            ->join('subjects', 'subjects.id', '=', 'curriculum.subject_id')
            ->where('curriculum.program_id', $programId)
            ->groupBy('curriculum.year_level', 'curriculum.semester')
            ->get([
                'curriculum.year_level',
                'curriculum.semester',
                DB::raw('SUM(subjects.units) as units'),
                DB::raw('COUNT(*) as subjects'),
            ])
            ->map(fn ($row) => [
                'year_level' => (int) $row->year_level,
                'semester'   => (int) $row->semester,
                'units'      => (int) $row->units,
                'subjects'   => (int) $row->subjects,
                'over_max'   => (int) $row->units > AcademicLoadValidationService::MAXIMUM_UNITS,
            ])
            ->sortBy([['year_level', 'asc'], ['semester', 'asc']])
            ->values();

        $years = $terms->groupBy('year_level')
            ->map(fn ($rows, $yearLevel) => [
                'year_level' => (int) $yearLevel,
                'units'      => $rows->sum('units'),
                'subjects'   => $rows->sum('subjects'),
            ])
            ->values();

        return [
            'maximum_units' => AcademicLoadValidationService::MAXIMUM_UNITS,
            'terms'         => $terms->all(),
            'years'         => $years->all(),
            'program'       => [
                'units'    => $terms->sum('units'),
                'subjects' => $terms->sum('subjects'),
            ],
        ];
    }
}
