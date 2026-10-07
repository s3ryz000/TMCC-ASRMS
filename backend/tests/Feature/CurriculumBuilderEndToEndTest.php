<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\OfficialTranscriptExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #30: a program built in the Curriculum Builder carries a student from
 * enrollment to the transcript, using only the endpoints the screens call:
 *
 *   New Curriculum (#70) → prerequisites (#27) → New Student (#75) → grades →
 *   next term, where builder prerequisites are enforced → the #28 guard →
 *   clone and edit the copy (#31) → transcript.
 */
class CurriculumBuilderEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        // Reusable subjects already in another program's curriculum.
        $this->makeCurriculum($this->makeProgram('BSTM', 'BS Tourism Management'), [
            'GEC4' => [1, 1], 'GEC1' => [1, 1], 'NSTP1' => [1, 1], 'GEC5' => [1, 2], 'GEC2' => [1, 2], 'NSTP2' => [1, 2],
        ]);
        SystemSetting::setValue('academic_year', '2026-2027');

        $this->staff = $this->makeUser('staff');
        Sanctum::actingAs($this->staff, ['*']);
    }

    /** The Existing subject tab: search, then pick the exact code. */
    private function searchSubject(string $code): array
    {
        $found = collect($this->getJson('/api/staff/subjects?' . http_build_query(['search' => $code, 'per_page' => 25]))->assertOk()->json('subjects'))
            ->firstWhere('code', $code);
        $this->assertNotNull($found, "{$code} not found by the subject search");

        return $found;
    }

    /** @return array<string, array> code => curriculum row, as the builder reads them */
    private function curriculum(int $programId): array
    {
        return collect($this->getJson("/api/staff/programs/{$programId}/curriculum")->assertOk()->json('curriculum'))
            ->keyBy(fn ($row) => $row['subject']['code'])
            ->all();
    }

    public function test_a_builder_made_program_from_enrollment_to_transcript(): void
    {
        // ── 1. New Curriculum: BSIT-E2E ────────────────────────────────────
        $gec4 = $this->searchSubject('GEC4');
        $this->assertSame(['BSTM'], $gec4['programs']);
        $prefix = collect($this->getJson('/api/staff/subject-prefixes')->assertOk()->json('prefixes'))->firstWhere('prefix', 'MOE');
        $this->assertSame('MOE - Microsoft Office / Productivity Tools', $prefix['label']);

        $id = fn (string $code) => $this->searchSubject($code)['id'];
        $created = $this->postJson('/api/staff/curriculums', [
            'program' => ['code' => 'BSIT-E2E', 'name' => 'BS Information Technology (E2E)'],
            'entries' => [
                ['subject_id' => $gec4['id'], 'year_level' => 1, 'semester' => 1],
                ['subject_id' => $id('GEC1'), 'year_level' => 1, 'semester' => 1],
                // The screen joins prefix + part with nothing in between: MOE + 9 = MOE9.
                ['new_subject' => ['code' => $prefix['prefix'] . '9', 'title' => 'Productivity Tools for IT', 'units' => 3], 'year_level' => 1, 'semester' => 1],
                ['subject_id' => $id('NSTP1'), 'year_level' => 1, 'semester' => 1],
                ['subject_id' => $id('GEC5'), 'year_level' => 1, 'semester' => 2],
                ['subject_id' => $id('NSTP2'), 'year_level' => 1, 'semester' => 2],
                ['subject_id' => $id('GEC2'), 'year_level' => 1, 'semester' => 2],
            ],
        ])->assertCreated()->assertJsonPath('new_subjects.0.code', 'MOE9');
        $programId = $created->json('program.id');
        $this->subjects['MOE9'] = Subject::where('code', 'MOE9')->firstOrFail();

        // Prerequisites (#27): NSTP2 needs NSTP1 AND MOE9; GEC2 needs GEC4 OR MOE9.
        $rows = $this->curriculum($programId);
        $this->putJson("/api/staff/curriculum/{$rows['NSTP2']['id']}/prerequisites", [
            'subject_ids' => $this->idsFor(['NSTP1', 'MOE9']), 'logic' => 'AND',
        ])->assertOk();
        $this->putJson("/api/staff/curriculum/{$rows['GEC2']['id']}/prerequisites", [
            'subject_ids' => $this->idsFor(['GEC4', 'MOE9']), 'logic' => 'OR',
        ])->assertOk();

        $totals = $this->getJson("/api/staff/programs/{$programId}/curriculum")->json('totals');
        $this->assertSame(['units' => 21, 'subjects' => 7], $totals['program']);
        $this->assertSame([12, 9], array_column($totals['terms'], 'units'));

        // ── 2. New Student with the first-term subjects (#75) ──────────────
        $studentId = $this->postJson('/api/staff/students', [
            'student_number'  => '260300',
            'first_name'      => 'Bea',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2006-03-03',
            'email'           => 'bea@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
            'program_id'      => $programId,
            'subject_ids'     => $this->idsFor(['GEC4', 'GEC1', 'MOE9', 'NSTP1']),
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ])->assertCreated()->json('student.student_id');
        $student = Student::findOrFail($studentId);
        $this->assertSame(4, $student->enrollments()->count());

        // ── 3. Grades: three passed, MOE9 (an AND prerequisite) failed ──────
        $this->submitGrades($student, ['GEC4' => 1.50, 'GEC1' => 1.75, 'NSTP1' => 1.25, 'MOE9' => 5.00])->assertOk();

        // ── 4. Next term: the AND subject is blocked, the OR one allowed ─────
        $progress = $this->academicProgress($student);
        $this->assertSame(['year_level' => 1, 'semester' => 2], array_intersect_key($progress['next_allowed_term'], array_flip(['year_level', 'semester'])));
        $this->assertFalse($this->availableSubject($progress, 'NSTP2')['eligible']);
        $this->assertContains('MOE9', $this->availableSubject($progress, 'NSTP2')['missing_prerequisites']);
        $this->assertTrue($this->availableSubject($progress, 'GEC2')['eligible']);
        $this->assertTrue($this->availableSubject($progress, 'GEC5')['eligible']);
        // Load rules: only GEC5 and GEC2 (6 units) are open, so the underload is allowed.
        $this->assertSame(6, $progress['max_eligible_units']);

        $this->enrollNextTerm($student, ['NSTP2'])
            ->assertStatus(422)
            ->assertJsonFragment(['MOE9 must be completed (Passed/Credited) before enrolling in NSTP2.']);
        $this->enrollNextTerm($student, ['GEC5', 'GEC2'])
            ->assertCreated()
            ->assertJsonPath('enrolled_count', 2);

        // ── 5. History guard (#28), then clone (#31) and edit the copy ──────
        $this->patchJson("/api/staff/curriculum/{$rows['GEC4']['id']}", ['year_level' => 2, 'semester' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', "1 student already has records for GEC4 in BSIT-E2E; it can't be moved or removed.");

        $before = $this->curriculum($programId);
        $cloneId = $this->postJson("/api/staff/programs/{$programId}/clone", ['code' => 'BSIT-E2E-2027', 'name' => 'BS Information Technology (2027)'])
            ->assertCreated()
            ->assertJsonPath('entries', 7)
            ->assertJsonPath('prerequisites', 4)
            ->json('program.id');
        $copy = $this->curriculum($cloneId);
        $this->assertSame(['GEC4', 'MOE9'], collect($copy['GEC2']['prerequisites'])->pluck('code')->sort()->values()->all());
        $this->assertSame('OR', $copy['GEC2']['prerequisite_logic']);

        $this->patchJson("/api/staff/curriculum/{$copy['GEC4']['id']}", ['year_level' => 2, 'semester' => 1])->assertOk();
        $this->assertSame(2, $this->curriculum($cloneId)['GEC4']['year_level']);
        $this->assertEquals($before, $this->curriculum($programId));

        // ── 6. Transcript ─────────────────────────────────────────────────
        $pdf = $this->get("/api/staff/students/{$studentId}/transcript")->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());

        $html = (new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml'))
            ->invoke(app(OfficialTranscriptExportService::class), $student->fresh(['program', 'grades.subject']), 'TOR', 'now');
        // The embedded logo (#98) is too long for the row pattern to scan past.
        $html = preg_replace('#data:image/[a-z]+;base64,[A-Za-z0-9+/=]+#', 'data:image', $html);
        $row = function (string $code) use ($html) {
            preg_match('#<tr[^>]*>(?:(?!</tr>).)*\b' . $code . '\b.*?</tr>#s', $html, $m);
            $this->assertNotEmpty($m, "{$code} is not on the transcript");

            return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</td>', ' | ', $m[0]))));
        };
        $this->assertStringContainsString('SANTOS, BEA', $html);
        // Course code | description | final | re-exam | credit: the builder-made subject with its failing grade.
        $this->assertSame('MOE9 | Productivity Tools for IT | 5.00 | | 3.00 |', $row('MOE9'));
        $this->assertSame('GEC4 | Subject GEC4 | 1.50 | | 3.00 |', $row('GEC4'));
        $this->assertStringContainsString('GEC2', $html);
        $this->assertStringNotContainsString('NSTP2', $html);
    }
}
