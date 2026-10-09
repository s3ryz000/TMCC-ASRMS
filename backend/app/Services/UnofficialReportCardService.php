<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Student;
use App\Support\AcademicStatus;
use App\Support\SchoolLogo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A student's unofficial report card for one semester (#34): subjects, units,
 * grades, the semestral GWA and honors, marked on every page as an unofficial
 * copy. The official transcript stays request-only, signed and sealed by the
 * registrar, so this copy can't stand in for it. A term with INC or missing
 * grades still prints; those rows show their status.
 */
class UnofficialReportCardService
{
    public const NOTICE = "Unofficial copy \u{2013} not valid without registrar's seal";

    public function __construct(private readonly AcademicStandingService $standing)
    {
    }

    /** The terms the student has grades for, oldest first: [['academic_year' => ..., 'semester' => ...], ...]. */
    public function terms(Student $student): array
    {
        return Grade::where('student_id', $student->student_id)
            ->get(['academic_year', 'semester'])
            ->map(fn ($g) => ['academic_year' => (string) $g->academic_year, 'semester' => (string) $g->semester])
            ->unique(fn ($t) => $t['academic_year'] . '|' . $t['semester'])
            ->sortBy(fn ($t) => $t['academic_year'] . '|' . $t['semester'])
            ->values()
            ->all();
    }

    /** The term's grades, or an empty collection when the student has none for it. */
    public function grades(Student $student, string $academicYear, string $semester): Collection
    {
        return Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->get()
            ->sortBy(fn ($g) => $g->subject?->code ?? '')
            ->values();
    }

    /** The PDF's bytes. */
    public function render(Student $student, string $academicYear, string $semester): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($student, $academicYear, $semester));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(Student $student, string $academicYear, string $semester): string
    {
        return sprintf(
            'UNOFFICIAL_REPORT_CARD_%s_%s_SEM%s.pdf',
            Str::upper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($student->student_number ?? 'STUDENT'))),
            preg_replace('/[^0-9-]/', '', $academicYear),
            preg_replace('/[^A-Za-z0-9]/', '', $semester),
        );
    }

    public function html(Student $student, string $academicYear, string $semester): string
    {
        $student->loadMissing('program');
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $grades = $this->grades($student, $academicYear, $semester);
        $gwa = $this->standing->computeSemesterGpa($student, $academicYear, $semester);
        $deansList = $this->standing->checkDeanList($student, $academicYear, $semester, $gwa);
        $latin = $this->standing->checkLatinHonorEligibility($student, $this->standing->computeOverallGwa($student));

        $rows = '';
        $totalUnits = 0.0;
        $earnedUnits = 0.0;
        foreach ($grades as $grade) {
            $units = (float) ($grade->subject?->units ?? 0);
            // A cancelled enrollment is listed but carries no load.
            if ($grade->status !== AcademicStatus::CANCELLED) {
                $totalUnits += $units;
            }
            if (in_array($grade->status, AcademicStatus::PASSED_GROUP, true)) {
                $earnedUnits += $units;
            }

            // No numeric grade yet (INC, still enrolled, not encoded): show why.
            $value = $grade->grade_value !== null
                ? number_format((float) $grade->grade_value, 2)
                : ($grade->status === AcademicStatus::ENROLLED || ! $grade->status ? 'No grade yet' : $grade->status);

            $rows .= '<tr>'
                . '<td>' . $e($grade->subject?->code) . '</td>'
                . '<td>' . $e($grade->subject?->title) . '</td>'
                . '<td class="c">' . $e(rtrim(rtrim(number_format($units, 1), '0'), '.')) . '</td>'
                . '<td class="c">' . $e($value) . '</td>'
                . '<td class="c">' . $e($grade->remarks ?: $grade->status) . '</td>'
                . '</tr>';
        }
        if ($grades->isEmpty()) {
            $rows = '<tr><td colspan="5" class="c muted">No grades on file for this semester.</td></tr>';
        }

        $fmtUnits = fn (float $u) => rtrim(rtrim(number_format($u, 1), '0'), '.');
        $name = $e(Str::upper(trim($student->last_name . ', ' . trim($student->first_name . ' ' . $student->middle_name), ', ')));
        $studentNo = $e($student->student_number);
        $program = $e($student->program?->name ?? $student->program?->code ?? '');
        $term = $e(self::semesterLabel($semester) . ', A.Y. ' . $academicYear);
        $gwaText = $gwa !== null ? number_format($gwa, 2) : 'Not available yet';
        $deans = $deansList['eligible'] ? "Dean's List" : 'None';
        $latinText = $latin['eligible'] ? $e($latin['honor']) . ' (based on the overall GWA so far)' : 'None';
        $notice = $e(self::NOTICE);
        $generated = $e(now()->format('F j, Y g:i A'));
        $logo = SchoolLogo::dataUri();
        $logoHtml = $logo !== '' ? '<img src="' . $logo . '" alt="TMCC logo" style="width:60px;height:60px;">' : '';
        $total = $fmtUnits($totalUnits);
        $earned = $fmtUnits($earnedUnits);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Unofficial Report Card</title>
<style>
* { margin: 0; padding: 0; }
@page { margin: 16mm 18mm 20mm 18mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #000; }
.wm { position: fixed; top: 38%; left: -10%; width: 120%; text-align: center; font-size: 60pt; font-weight: bold;
      color: rgba(0,0,0,0.06); transform: rotate(-35deg); z-index: -100; letter-spacing: 6px; white-space: nowrap; }
.ftr { position: fixed; bottom: -8mm; left: 0; right: 0; border-top: 0.5pt solid #999; padding-top: 2px; font-size: 7pt; color: #555; }
.hdr { width: 100%; border-collapse: collapse; }
.hdr td { vertical-align: middle; }
.sch-name { font-size: 12pt; font-weight: bold; }
.sch-addr { font-size: 8pt; }
.title { text-align: center; font-size: 14pt; font-weight: bold; margin: 10px 0 2px; }
.notice { text-align: center; font-size: 9pt; font-weight: bold; color: #b00; border: 1pt solid #b00; padding: 3px; margin: 4px 0 10px; }
.info { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.info td { padding: 1px 0; }
.lb { width: 110px; }
.vl { font-weight: bold; }
.g { width: 100%; border-collapse: collapse; }
.g th, .g td { border: 0.5pt solid #000; padding: 3px 5px; font-size: 8.5pt; }
.g th { background: #eee; }
.c { text-align: center; }
.muted { color: #666; }
.sum { width: 60%; border-collapse: collapse; margin-top: 10px; }
.sum td { padding: 2px 0; }
</style>
</head>
<body>
<div class="wm">UNOFFICIAL COPY</div>
<div class="ftr">{$notice}. Generated {$generated} from the student portal.</div>

<table class="hdr"><tr>
  <td style="width:70px;">{$logoHtml}</td>
  <td>
    <div class="sch-name">TRECE MARTIRES CITY COLLEGE</div>
    <div class="sch-addr">Trece Martires City, Cavite</div>
    <div class="sch-addr">Office of the Registrar</div>
  </td>
</tr></table>

<div class="title">REPORT OF GRADES</div>
<div class="notice">{$notice}</div>

<table class="info">
<tr><td class="lb">Name:</td><td class="vl">{$name}</td></tr>
<tr><td class="lb">Student No.:</td><td class="vl">{$studentNo}</td></tr>
<tr><td class="lb">Program:</td><td class="vl">{$program}</td></tr>
<tr><td class="lb">Term:</td><td class="vl">{$term}</td></tr>
</table>

<table class="g">
<thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr></thead>
<tbody>{$rows}</tbody>
</table>

<table class="sum">
<tr><td class="lb">Units enrolled:</td><td class="vl">{$total}</td></tr>
<tr><td class="lb">Units earned:</td><td class="vl">{$earned}</td></tr>
<tr><td class="lb">Semestral GWA:</td><td class="vl">{$gwaText}</td></tr>
<tr><td class="lb">Honors this term:</td><td class="vl">{$deans}</td></tr>
<tr><td class="lb">Latin honors:</td><td class="vl">{$latinText}</td></tr>
</table>
</body>
</html>
HTML;
    }

    public static function semesterLabel(string $semester): string
    {
        return match (Str::lower(trim($semester))) {
            '1', '1st', 'first', '1st semester', 'first semester' => '1st Semester',
            '2', '2nd', 'second', '2nd semester', 'second semester' => '2nd Semester',
            '3', 'summer', 'midyear' => 'Summer',
            default => $semester,
        };
    }
}
