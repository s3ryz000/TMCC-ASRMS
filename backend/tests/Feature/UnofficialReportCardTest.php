<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\UnofficialReportCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #34: a student downloads an unofficial report card for any semester they
 * have grades for, at any time and without a request. It lists the subjects,
 * units, grades, the semestral GWA and honors, is marked unofficial, prints
 * with INC or missing grades, and only ever shows the student's own record.
 */
class UnofficialReportCardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const TERM = '/api/student/report-card?academic_year=2026-2027&semester=1';

    private Student $student;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $program = $this->makeProgram();
        $this->makeCurriculum($program, ['IT101' => [1, 1, 3], 'IT102' => [1, 1, 3], 'GE101' => [1, 1, 2], 'IT201' => [1, 2, 3]]);
        $this->studentUser = $this->makeUser('student', '2026-0001');
        $this->student = $this->makeStudent($program, [], $this->studentUser);
    }

    private function html(string $semester = '1'): string
    {
        return app(UnofficialReportCardService::class)->html($this->student, '2026-2027', $semester);
    }

    public function test_the_student_downloads_a_pdf_for_a_semester(): void
    {
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.25, 'Passed');
        Sanctum::actingAs($this->studentUser, ['*']);

        $response = $this->get(self::TERM)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        $this->assertStringContainsString('UNOFFICIAL_REPORT_CARD_2026-0001_2026-2027_SEM1.pdf', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('system_logs', ['action' => 'Unofficial report card downloaded', 'user_id' => $this->studentUser->id]);
    }

    public function test_it_lists_subjects_units_grades_gwa_and_the_unofficial_notice(): void
    {
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.25, 'Passed');
        $this->recordGrade($this->student, 'GE101', '2026-2027', 1, 2.00, 'Passed');
        $this->recordGrade($this->student, 'IT201', '2026-2027', 2, 3.00, 'Passed');

        $html = $this->html();

        $this->assertStringContainsString('DELA CRUZ, JUAN', $html);
        $this->assertStringContainsString('1st Semester, A.Y. 2026-2027', $html);
        $this->assertStringContainsString('IT101', $html);
        $this->assertStringContainsString('1.25', $html);
        $this->assertStringContainsString('GE101', $html);
        // Another semester's subject is not on this card.
        $this->assertStringNotContainsString('IT201', $html);
        // (1.25 × 3 + 2.00 × 2) ÷ 5 = 1.55
        $this->assertMatchesRegularExpression('/Semestral GWA:<\/td><td class="vl">1\.55</', $html);
        $this->assertMatchesRegularExpression('/Units enrolled:<\/td><td class="vl">5</', $html);
        $this->assertStringContainsString('Unofficial copy – not valid without registrar&#039;s seal', $html);
        $this->assertStringContainsString('UNOFFICIAL COPY', $html);
    }

    public function test_it_shows_deans_list_and_latin_honors_when_earned(): void
    {
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.25, 'Passed');
        $this->recordGrade($this->student, 'IT102', '2026-2027', 1, 1.50, 'Passed');

        $html = $this->html();

        $this->assertStringContainsString("Honors this term:</td><td class=\"vl\">Dean's List<", $html);
        $this->assertStringContainsString('Magna Cum Laude', $html);
    }

    public function test_it_prints_with_inc_and_missing_grades(): void
    {
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.75, 'Passed');
        $this->recordGrade($this->student, 'IT102', '2026-2027', 1, null, 'INC');
        $this->recordGrade($this->student, 'GE101', '2026-2027', 1, null, 'Enrolled');
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->get(self::TERM)->assertOk();

        $html = $this->html();
        $this->assertStringContainsString('<td class="c">INC</td>', $html);
        $this->assertStringContainsString('No grade yet', $html);
        $this->assertMatchesRegularExpression('/Honors this term:<\/td><td class="vl">None</', $html);
        // Only IT101 is earned so far.
        $this->assertMatchesRegularExpression('/Units earned:<\/td><td class="vl">3</', $html);
    }

    public function test_the_terms_list_offers_only_semesters_with_grades(): void
    {
        $this->recordGrade($this->student, 'IT201', '2026-2027', 2, 2.00, 'Passed');
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.25, 'Passed');
        $this->recordGrade($this->student, 'IT102', '2026-2027', 1, 1.50, 'Passed');
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/student/report-card/terms')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['academic_year' => '2026-2027', 'semester' => '1', 'label' => '1st Semester, A.Y. 2026-2027'],
                ['academic_year' => '2026-2027', 'semester' => '2', 'label' => '2nd Semester, A.Y. 2026-2027'],
            ]]);
    }

    public function test_a_semester_without_grades_is_not_found(): void
    {
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/student/report-card?academic_year=2030-2031&semester=1')
            ->assertNotFound()
            ->assertJsonPath('message', 'You have no grades on file for that semester.');
        $this->getJson('/api/student/report-card')->assertStatus(422)->assertJsonValidationErrors(['academic_year', 'semester']);
    }

    public function test_only_the_signed_in_students_own_grades_are_used(): void
    {
        $otherUser = $this->makeUser('student', '2026-0002');
        $other = $this->makeStudent(\App\Models\Program::first(), ['student_number' => '2026-0002', 'email' => 'ana@tmcc.test', 'last_name' => 'Santos'], $otherUser);
        $this->recordGrade($other, 'IT101', '2026-2027', 1, 1.00, 'Passed');
        Sanctum::actingAs($this->studentUser, ['*']);

        // A has no grades that term; B's do not leak through a student_id parameter.
        $this->getJson(self::TERM . '&student_id=' . $other->student_id)->assertNotFound();
        $this->getJson('/api/student/report-card/terms?student_id=' . $other->student_id)->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_staff_and_admins_cannot_use_the_student_report_card(): void
    {
        $this->recordGrade($this->student, 'IT101', '2026-2027', 1, 1.25, 'Passed');

        foreach (['staff', 'admin'] as $role) {
            Sanctum::actingAs($this->makeUser($role, "{$role}01"), ['*']);
            $this->getJson(self::TERM)->assertForbidden();
            $this->getJson('/api/student/report-card/terms')->assertForbidden();
        }
    }

    public function test_signing_in_is_required(): void
    {
        $this->getJson(self::TERM)->assertUnauthorized();
    }
}
