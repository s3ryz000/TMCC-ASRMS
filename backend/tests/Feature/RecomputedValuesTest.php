<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicStandingService;
use App\Services\OfficialTranscriptExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #58: what every screen shows comes straight from the database. After the
 * registrar corrects grades (INC -> Passed, Failed -> Passed), the record
 * page, the student portal and the transcript all show the GWA, units and
 * honors AcademicStandingService computes from the stored grades.
 */
class RecomputedValuesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $registrar;
    private User $studentUser;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->registrar = $this->makeUser('staff');
        $program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($program, ['GEC4' => [1, 1], 'TPC1' => [1, 1], 'TPC2' => [1, 1, 4]]);
        $this->studentUser = $this->makeUser('student', '260001');
        $this->student = $this->makeStudent($program, ['student_number' => '260001'], $this->studentUser);

        foreach (['GEC4' => [1.25, 'Passed'], 'TPC1' => [null, 'INC'], 'TPC2' => [5.00, 'Failed']] as $code => [$value, $status]) {
            $enrollment = Enrollment::create([
                'student_id' => $this->student->student_id, 'subject_id' => $this->subjects[$code]->id,
                'academic_year' => '2026-2027', 'semester' => '1', 'year_level' => 1, 'status' => $status,
            ]);
            $this->recordGrade($this->student, $code, '2026-2027', 1, $value, $status)->update(['enrollment_id' => $enrollment->id]);
        }
    }

    /** GWA, honors and units as each screen's endpoint returns them, next to the service's own result. */
    private function everyView(): array
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $record = $this->getJson("/api/staff/students/{$this->student->student_id}/academic-summary")->assertOk()->json();
        Sanctum::actingAs($this->studentUser, ['*']);
        $portal = $this->getJson('/api/student/academic-summary')->assertOk()->json();
        $service = app(AcademicStandingService::class)->getAcademicSummary($this->student->fresh());

        $pick = fn (array $summary, array $curriculum) => [
            'gwa'       => $summary['overall_gwa'],
            'honors'    => $summary['latin_honors'],
            'terms'     => array_map(fn ($t) => [$t['academic_year'], $t['semester'], $t['gpa']], $summary['terms']),
            'completed' => $curriculum['completed_units'],
            'left'      => $curriculum['units_left'],
        ];

        return [
            'record page'    => $pick($record['summary'], $record['curriculum']),
            'student portal' => $pick($portal['summary'], $portal['curriculum']),
            'service'        => $pick($service, $record['curriculum']),
        ];
    }

    private function transcriptRow(string $code): string
    {
        $html = (new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml'))
            ->invoke(app(OfficialTranscriptExportService::class), $this->student->fresh(['program', 'grades.subject']), 'TOR', 'now');
        // The embedded logo (#98) is too long for the row pattern to scan past.
        $html = preg_replace('#data:image/[a-z]+;base64,[A-Za-z0-9+/=]+#', 'data:image', $html);
        preg_match('#<tr[^>]*>(?:(?!</tr>).)*\b' . $code . '\b.*?</tr>#s', $html, $m);

        return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</td>', ' | ', $m[0] ?? ''))));
    }

    private function correct(string $code, float $value): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $grade = Grade::where('student_id', $this->student->student_id)->where('subject_id', $this->subjects[$code]->id)->firstOrFail();

        $this->putJson("/api/staff/students/{$this->student->student_id}/grades/{$grade->id}", [
            'grade_value' => $value, 'supporting_document_reference' => "Completion form {$code}",
        ])->assertOk();
    }

    public function test_corrections_show_the_same_recomputed_values_everywhere(): void
    {
        $before = $this->everyView();
        $this->assertSame($before['service'], $before['record page']);
        $this->assertSame($before['service'], $before['student portal']);
        // The failed 5.00 counts; INC doesn't: (1.25*3 + 5.00*4) / 7 = 3.39
        $this->assertSame(3.39, $before['service']['gwa']);
        $this->assertSame(3, $before['service']['completed']);

        $this->correct('TPC1', 1.50); // INC -> Passed
        $this->correct('TPC2', 1.75); // Failed -> Passed (4 units)

        $after = $this->everyView();
        $this->assertSame($after['service'], $after['record page']);
        $this->assertSame($after['service'], $after['student portal']);
        // (1.25*3 + 1.50*3 + 1.75*4) / 10 = 1.525
        $this->assertEqualsWithDelta(1.53, $after['service']['gwa'], 0.005);
        $this->assertSame(10, $after['service']['completed']);
        $this->assertSame(0, $after['service']['left']);
        $this->assertNotSame($before['service']['gwa'], $after['service']['gwa']);

        // The transcript prints the stored, corrected grades and units.
        $this->assertStringContainsString('| 1.50 |', $this->transcriptRow('TPC1'));
        $this->assertStringContainsString('| 1.75 |', $this->transcriptRow('TPC2'));
        $this->assertStringContainsString('| 4.00 |', $this->transcriptRow('TPC2'));
        $this->assertSame('Passed', Grade::where('subject_id', $this->subjects['TPC2']->id)->value('status'));
    }
}
