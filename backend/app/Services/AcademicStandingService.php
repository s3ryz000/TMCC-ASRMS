<?php

namespace App\Services;

use App\Support\AcademicStatus;
use App\Models\Student;
use App\Models\Grade;

class AcademicStandingService
{
    /**
     * Get full academic summary including GWA, GPAs, and Latin honors eligibility.
     *
     * Dean's List and President's List are not computed: TMCC's Dean's List
     * comes from each school, not the Registrar's Office, and TMCC has no
     * President's List (client walkthrough, 21 Oct 2026, #102).
     */
    public function getAcademicSummary(Student $student): array
    {
        $overallGwa = $this->computeOverallGwa($student);
        $latinHonors = $this->checkLatinHonorEligibility($student, $overallGwa);

        $terms = [];
        $annualGroups = [];

        // Fetch all distinct terms the student has grades for
        $grades = Grade::where('student_id', $student->student_id)
            ->get();

        $termKeys = $grades->map(fn($g) => $g->academic_year . '|' . $g->semester)->unique();

        foreach ($termKeys as $key) {
            [$ay, $sem] = explode('|', $key);
            $terms[] = [
                'academic_year' => $ay,
                'semester' => $sem,
                'gpa' => $this->computeSemesterGpa($student, $ay, $sem),
            ];

            if (!isset($annualGroups[$ay])) {
                $annualGroups[$ay] = [];
            }
            $annualGroups[$ay][] = $sem;
        }

        $years = [];
        foreach ($annualGroups as $ay => $sems) {
            $years[] = [
                'academic_year' => $ay,
                'gpa' => $this->computeAnnualGpa($student, $ay),
            ];
        }

        // Sort terms chronologically (simplistic sort by AY and Sem)
        usort($terms, function($a, $b) {
            if ($a['academic_year'] === $b['academic_year']) {
                return $a['semester'] <=> $b['semester'];
            }
            return $a['academic_year'] <=> $b['academic_year'];
        });

        return [
            'overall_gwa' => $overallGwa,
            'latin_honors' => $latinHonors,
            'terms' => $terms,
            'years' => $years,
        ];
    }

    /**
     * Compute Semestral GPA
     */
    public function computeSemesterGpa(Student $student, string $academicYear, string $semester): ?float
    {
        $grades = Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->get();

        return $this->calculateWeightedAverage($grades);
    }

    /**
     * Compute Annual GPA
     */
    public function computeAnnualGpa(Student $student, string $academicYear): ?float
    {
        $grades = Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->where('academic_year', $academicYear)
            ->get();

        return $this->calculateWeightedAverage($grades);
    }

    /**
     * Compute Overall GWA
     */
    public function computeOverallGwa(Student $student): ?float
    {
        $grades = Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->get();

        return $this->calculateWeightedAverage($grades);
    }

    /**
     * Recompute and cache the overall GWA in the students table
     */
    public function recomputeAndCacheOverallGwa(Student $student): void
    {
        $gwa = $this->computeOverallGwa($student);
        $student->update(['GPA' => $gwa]);
    }

    /**
     * Check Latin Honor Eligibility
     */
    public function checkLatinHonorEligibility(Student $student, ?float $overallGwa): array
    {
        if ($overallGwa === null) {
            return ['eligible' => false, 'honor' => null, 'reason' => 'No numeric grades available.'];
        }

        $grades = Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->get();

        $gradesCheck = $this->evaluateHonorGrades($grades);
        if (!$gradesCheck['eligible']) {
            return array_merge($gradesCheck, ['honor' => null]);
        }

        if ($overallGwa >= 1.00 && $overallGwa <= 1.20) {
            return ['eligible' => true, 'honor' => 'Summa Cum Laude', 'reason' => 'Meets all requirements.'];
        }

        if ($overallGwa >= 1.21 && $overallGwa <= 1.45) {
            return ['eligible' => true, 'honor' => 'Magna Cum Laude', 'reason' => 'Meets all requirements.'];
        }

        if ($overallGwa >= 1.46 && $overallGwa <= 1.75) {
            return ['eligible' => true, 'honor' => 'Cum Laude', 'reason' => 'Meets all requirements.'];
        }

        return ['eligible' => false, 'honor' => null, 'reason' => "Overall GWA ($overallGwa) does not meet Latin Honor requirements."];
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function calculateWeightedAverage($grades): ?float
    {
        $totalWeighted = 0;
        $totalUnits = 0;

        foreach ($grades as $grade) {
            // Exclude Credited and Withdrawn
            if (in_array($grade->status, [AcademicStatus::CREDITED, AcademicStatus::WITHDRAWN, AcademicStatus::FDA, AcademicStatus::DRP, AcademicStatus::CANCELLED, AcademicStatus::ENROLLED])) {
                continue;
            }

            // Exclude if no grade value
            if ($grade->grade_value === null) {
                // If it's a Failed grade without value (e.g. 5.00), treat as 5.00
                if ($grade->status === AcademicStatus::FAILED) {
                    $units = $grade->subject?->units ?? 0;
                    $totalWeighted += (5.00 * $units);
                    $totalUnits += $units;
                }
                continue;
            }

            $units = $grade->subject?->units ?? 0;
            $totalWeighted += ($grade->grade_value * $units);
            $totalUnits += $units;
        }

        if ($totalUnits === 0) {
            return null;
        }

        return round($totalWeighted / $totalUnits, 2);
    }

    private function evaluateHonorGrades($grades): array
    {
        $issues = [];

        foreach ($grades as $grade) {
            $code = $grade->subject?->code ?? 'Unknown Subject';

            if ($grade->status === AcademicStatus::ENROLLED) {
                $issues[] = "still enrolled in {$code}";
                continue;
            }

            if (in_array($grade->status, [AcademicStatus::FAILED, AcademicStatus::INC, AcademicStatus::FDA, AcademicStatus::DRP, AcademicStatus::WITHDRAWN, AcademicStatus::CANCELLED])) {
                $issues[] = "{$grade->status} in {$code}";
                continue;
            }

            if ($grade->grade_value !== null && $grade->grade_value > 2.00) {
                $issues[] = "{$code} ({$grade->grade_value})";
            }
        }

        if (empty($issues)) {
            return ['eligible' => true, 'reason' => 'Meets all requirements.'];
        }

        $list = implode(', ', $issues);
        return [
            'eligible' => false,
            'reason'   => "Not eligible due to: {$list}.",
        ];
    }
}
