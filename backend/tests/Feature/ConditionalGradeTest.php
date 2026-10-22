<?php

namespace Tests\Feature;

use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Services\AcademicStandingService;
use App\Support\AcademicStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * TMCC's grading scale (#104): 1.0 is the highest, 3.0 passes, 4.0 is
 * Conditional and 5.0 fails. A Conditional is later resolved to 3.0 or 5.0
 * only; until then it is not passed, blocks prerequisites and honors, and is
 * left out of the GWA.
 */
class ConditionalGradeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();

        // Y1S1: A, B      Y1S2: C (needs A), D (needs B)
        $this->makeCurriculum($this->program, [
            'A' => [1, 1],
            'B' => [1, 1],
            'C' => [1, 2],
            'D' => [1, 2],
        ], [
            'C' => ['A'],
            'D' => ['B'],
        ]);

        $this->student = $this->makeStudent($this->program);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function completeFirstTerm(array $grades): void
    {
        $this->enrollNextTerm($this->student, array_keys($grades))->assertCreated();
        $this->submitGrades($this->student, $grades)->assertOk();
    }

    private function gradeOf(string $code): Grade
    {
        return Grade::where('student_id', $this->student->student_id)
            ->where('subject_id', $this->subjects[$code]->id)
            ->latest('id')
            ->firstOrFail();
    }

    public function test_a_4_0_is_saved_as_conditional(): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);

        $grade = $this->gradeOf('A');
        $this->assertSame(AcademicStatus::CONDITIONAL, $grade->status);
        $this->assertSame('Conditional', $grade->remarks);
    }

    public function test_a_conditional_prerequisite_blocks_the_dependent_subject(): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);

        $progress = $this->academicProgress($this->student);
        $c = $this->availableSubject($progress, 'C');

        $this->assertFalse($c['eligible']);
        $this->assertSame('conditional', $c['prerequisite_status']);
        $this->assertSame('A is Conditional (4.0). Resolve it to 3.0 or 5.0 first.', $c['blocked_reason']);
        $this->assertTrue($this->availableSubject($progress, 'D')['eligible']);
    }

    public function test_a_conditional_is_left_out_of_the_gwa_and_blocks_honors(): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 1.00]);

        $summary = app(AcademicStandingService::class)->getAcademicSummary($this->student->fresh());

        // Only B counts until A is resolved.
        $this->assertSame(1.0, $summary['overall_gwa']);
        $this->assertFalse($summary['latin_honors']['eligible']);
        $this->assertStringContainsString('Conditional in A', $summary['latin_honors']['reason']);
    }

    /**
     * @dataProvider resolutions
     */
    public function test_a_conditional_resolves_to_3_0_or_5_0(float $value, string $status): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);

        $this->submitGrades($this->student, ['A' => $value])->assertOk();

        $grade = $this->gradeOf('A');
        $this->assertSame($status, $grade->status);
        $this->assertSame(AcademicStatus::CONDITIONAL, $grade->converted_from_status);
        $this->assertNotNull($grade->converted_at);

        $audit = EnrollmentAuditLog::where('subject_id', $this->subjects['A']->id)->latest('id')->firstOrFail();
        $this->assertSame(AcademicStatus::CONDITIONAL, $audit->old_status);
        $this->assertSame($status, $audit->new_status);
        $this->assertSame('conditional_resolved', $audit->action);
    }

    public static function resolutions(): array
    {
        return [
            'passed with 3.0' => [3.00, AcademicStatus::PASSED],
            'failed with 5.0' => [5.00, AcademicStatus::FAILED],
        ];
    }

    /**
     * @dataProvider refusedResolutions
     */
    public function test_a_conditional_cannot_become_any_other_grade(float|string $value): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);

        $this->submitGrades($this->student, ['A' => $value])->assertStatus(422);

        $this->assertSame(AcademicStatus::CONDITIONAL, $this->gradeOf('A')->status);
    }

    public static function refusedResolutions(): array
    {
        return [
            '1.0'       => [1.00],
            '2.5'       => [2.50],
            'INC'       => ['INC'],
            'Withdrawn' => ['Withdrawn'],
        ];
    }

    public function test_resolving_to_3_0_unlocks_the_dependent_subject(): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);
        $this->submitGrades($this->student, ['A' => 3.00])->assertOk();

        $c = $this->availableSubject($this->academicProgress($this->student), 'C');
        $this->assertTrue($c['eligible']);
    }

    public function test_a_conditional_cannot_be_retaken_before_it_is_resolved(): void
    {
        $this->completeFirstTerm(['A' => 4.00, 'B' => 2.00]);

        $this->enrollNextTerm($this->student, ['D'], ['A'])
            ->assertStatus(422)
            ->assertJsonFragment(['A has an unresolved INC or Conditional grade. Resolve it before creating a retake.']);
    }

    public function test_the_old_con_spelling_is_read_as_conditional(): void
    {
        $this->assertSame(AcademicStatus::CONDITIONAL, AcademicStatus::canonical('CON'));
        $this->assertSame(AcademicStatus::CONDITIONAL, AcademicStatus::canonical('conditional'));
    }
}
