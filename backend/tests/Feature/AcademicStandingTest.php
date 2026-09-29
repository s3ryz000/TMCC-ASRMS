<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicStandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * GWA, honors eligibility and the honors alert (A4), and recomputation of all
 * of them when the registrar corrects a grade (E1).
 */
class AcademicStandingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $staff;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();

        // Y1S1: A, B, G    Y1S2: C (needs A)
        $this->makeCurriculum($this->program, [
            'A' => [1, 1],
            'B' => [1, 1],
            'G' => [1, 1],
            'C' => [1, 2],
        ], ['C' => ['A']]);

        $this->staff = $this->makeUser('staff');
        $this->studentUser = $this->makeUser('student', '2026-0001');
        $this->student = $this->makeStudent($this->program, [], $this->studentUser);
    }

    private function gradeFirstTerm(float $a, float $b, float $g): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, $a, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, $b, 'Passed');
        $this->recordGrade($this->student, 'G', '2026-2027', 1, $g, 'Passed');
    }

    private function summary(): array
    {
        return app(AcademicStandingService::class)->getAcademicSummary($this->student->fresh());
    }

    // ------------------------------------------------------------- A4: rules

    public function test_gwa_is_the_unit_weighted_average(): void
    {
        $this->subjects['G']->update(['units' => 6]);
        $this->gradeFirstTerm(1.00, 2.00, 3.00);

        // (1.00*3 + 2.00*3 + 3.00*6) / 12 = 2.25
        $this->assertSame(2.25, $this->summary()['overall_gwa']);
    }

    public function test_credited_and_withdrawn_are_left_out_of_the_gwa(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 1.50, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, null, 'Credited');
        $this->recordGrade($this->student, 'G', '2026-2027', 1, null, 'Withdrawn');

        $this->assertSame(1.50, $this->summary()['overall_gwa']);
    }

    public function test_deans_list_needs_a_semester_gpa_of_1_75_or_better(): void
    {
        $this->gradeFirstTerm(1.50, 1.75, 2.00);                   // GPA 1.75
        $this->recordGrade($this->student, 'C', '2026-2027', 2, 2.00, 'Passed'); // GPA 2.00

        $terms = collect($this->summary()['terms'])->keyBy('semester');

        $this->assertTrue($terms['1']['deans_list']['eligible']);
        $this->assertFalse($terms['2']['deans_list']['eligible']);
        $this->assertStringContainsString('lower than the required 1.75', $terms['2']['deans_list']['reason']);
    }

    public function test_a_grade_below_2_00_blocks_honors_despite_a_high_gpa(): void
    {
        $this->gradeFirstTerm(1.00, 1.00, 2.25); // GPA 1.42

        $summary = $this->summary();

        $this->assertFalse($summary['terms'][0]['deans_list']['eligible']);
        $this->assertStringContainsString('G (2.25)', $summary['terms'][0]['deans_list']['reason']);
        $this->assertFalse($summary['latin_honors']['eligible']);
    }

    public function test_inc_blocks_honors(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 1.00, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 1.00, 'Passed');
        $this->recordGrade($this->student, 'G', '2026-2027', 1, null, 'INC');

        $summary = $this->summary();

        $this->assertFalse($summary['latin_honors']['eligible']);
        $this->assertStringContainsString('INC in G', $summary['latin_honors']['reason']);
    }

    public function test_presidents_list_uses_the_annual_gpa(): void
    {
        $this->gradeFirstTerm(1.25, 1.25, 1.25);
        $this->recordGrade($this->student, 'C', '2026-2027', 2, 1.50, 'Passed');

        $year = $this->summary()['years'][0];

        $this->assertSame('2026-2027', $year['academic_year']);
        $this->assertSame(1.31, $year['gpa']);
        $this->assertTrue($year['presidents_list']['eligible']);
    }

    /**
     * @dataProvider latinHonorBands
     */
    public function test_latin_honor_bands(float $grade, ?string $honor): void
    {
        $this->gradeFirstTerm($grade, $grade, $grade);

        $latin = $this->summary()['latin_honors'];

        $this->assertSame($honor !== null, $latin['eligible']);
        $this->assertSame($honor, $latin['honor']);
    }

    public static function latinHonorBands(): array
    {
        return [
            '1.00 summa'     => [1.00, 'Summa Cum Laude'],
            '1.20 summa'     => [1.20, 'Summa Cum Laude'],
            '1.21 magna'     => [1.21, 'Magna Cum Laude'],
            '1.45 magna'     => [1.45, 'Magna Cum Laude'],
            '1.46 cum laude' => [1.46, 'Cum Laude'],
            '1.75 cum laude' => [1.75, 'Cum Laude'],
            '1.76 none'      => [1.76, null],
        ];
    }

    // ------------------------------------------------------------- A4: alert

    public function test_student_dashboard_raises_the_latin_honors_alert(): void
    {
        $this->gradeFirstTerm(1.00, 1.25, 1.00); // GWA 1.08
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/student/academic-summary')
            ->assertOk()
            ->assertJsonPath('summary.latin_honors.honor', 'Summa Cum Laude')
            ->assertJsonFragment([
                'type'    => 'success',
                'message' => 'Congratulations! You are currently eligible for Latin Honors: Summa Cum Laude',
            ]);
    }

    public function test_no_honors_alert_when_not_eligible(): void
    {
        $this->gradeFirstTerm(2.00, 2.00, 2.00);
        Sanctum::actingAs($this->studentUser, ['*']);

        $notifications = $this->getJson('/api/student/academic-summary')->assertOk()->json('notifications');

        $this->assertSame([], array_filter($notifications, fn ($n) => $n['type'] === 'success'));
    }

    public function test_staff_see_the_same_eligibility(): void
    {
        $this->gradeFirstTerm(1.50, 1.50, 1.50);
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson("/api/staff/students/{$this->student->student_id}/academic-summary")
            ->assertOk()
            ->assertJsonPath('summary.latin_honors.honor', 'Cum Laude')
            ->assertJsonPath('summary.terms.0.deans_list.eligible', true);
    }

    // --------------------------------------------- E1: correction recomputes

    public function test_correcting_a_grade_recomputes_gwa_and_honors(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B', 'G'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 1.00, 'B' => 3.00, 'G' => 2.00])->assertOk();

        $this->assertSame('2.00', $this->student->fresh()->GPA);
        $this->assertFalse($this->summary()['terms'][0]['deans_list']['eligible']);

        $this->submitGrades($this->student, ['B' => 1.25])->assertOk();

        // (1.00 + 1.25 + 2.00) / 3 = 1.42
        $this->assertSame('1.42', $this->student->fresh()->GPA);
        $summary = $this->summary();
        $this->assertSame(1.42, $summary['overall_gwa']);
        $this->assertTrue($summary['terms'][0]['deans_list']['eligible']);
        $this->assertSame('Magna Cum Laude', $summary['latin_honors']['honor']);
    }

    public function test_correction_keeps_the_enrollment_status_in_step(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B', 'G'])->assertCreated();

        $this->submitGrades($this->student, ['A' => 5.00])->assertOk();

        $this->assertDatabaseHas('enrollments', ['subject_id' => $this->subjects['A']->id, 'status' => 'Failed']);
        $this->assertDatabaseHas('grades', ['subject_id' => $this->subjects['A']->id, 'status' => 'Failed', 'remarks' => 'Failed']);
    }

    public function test_resolving_an_inc_unlocks_the_dependent_subject(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B', 'G'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 'INC', 'B' => 2.00, 'G' => 2.00])->assertOk();

        $this->assertFalse($this->availableSubject($this->academicProgress($this->student), 'C')['eligible']);

        $this->submitGrades($this->student, ['A' => 2.00])->assertOk();

        $this->assertTrue($this->availableSubject($this->academicProgress($this->student), 'C')['eligible']);

        $grade = Grade::where('subject_id', $this->subjects['A']->id)->first();
        $this->assertSame('Passed', $grade->status);
        $this->assertSame('INC', $grade->converted_from_status);
        $this->assertNotNull($grade->converted_at);
        $this->assertDatabaseHas('enrollment_audit_logs', ['subject_id' => $this->subjects['A']->id, 'action' => 'inc_to_passed']);
    }

    public function test_correcting_a_passed_prerequisite_to_failed_blocks_the_dependent_subject(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B', 'G'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 2.00, 'B' => 2.00, 'G' => 2.00])->assertOk();

        $this->assertTrue($this->availableSubject($this->academicProgress($this->student), 'C')['eligible']);

        $this->submitGrades($this->student, ['A' => 5.00])->assertOk();

        $c = $this->availableSubject($this->academicProgress($this->student), 'C');
        $this->assertFalse($c['eligible']);
        $this->assertSame('failed', $c['prerequisite_status']);
    }

    public function test_credited_requires_a_supporting_document(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B', 'G'])->assertCreated();

        $this->submitGrades($this->student, ['A' => 'Credited'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Grade update failed.']);

        $this->assertDatabaseMissing('grades', ['subject_id' => $this->subjects['A']->id, 'status' => 'Credited']);
    }
}
