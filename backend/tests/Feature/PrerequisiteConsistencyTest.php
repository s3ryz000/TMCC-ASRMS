<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Services\AcademicLoadValidationService;
use App\Services\RetakeEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * D1: every module answers "what has this student passed?" and "are this
 * subject's prerequisites met?" the same way. Before this, the retake and
 * load services each carried their own copies, which ignored OR groups, the
 * legacy single prerequisite, or remarks-only legacy passes.
 *
 * E3: the pickers receive every prerequisite and its AND/OR logic.
 */
class PrerequisiteConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;

    /** A next term whose semester matches the 1st-semester retakes below. */
    private const NEXT_TERM = ['can_add' => true, 'year_level' => 2, 'semester' => 1, 'academic_year' => '2027-2028'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();

        // All 1st semester, so a failed R is a retake candidate in NEXT_TERM.
        $this->makeCurriculum($this->program, [
            'A' => [1, 1],
            'B' => [1, 1],
            'R' => [1, 1],
        ], ['R' => ['A', 'B']]);

        $this->student = $this->makeStudent($this->program);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function useOrLogic(): void
    {
        $this->curricula['R']->update(['prerequisite_logic' => 'OR']);
    }

    private function retakeErrors(): array
    {
        return app(RetakeEligibilityService::class)
            ->validateRetakeSubjects($this->student, [$this->subjects['R']->id], self::NEXT_TERM)['errors'];
    }

    private function maxEligibleUnits(): int
    {
        return app(AcademicLoadValidationService::class)->computeMaxEligibleUnits($this->student, self::NEXT_TERM);
    }

    // ------------------------------------------------------ Curriculum model

    public function test_and_logic_reports_each_unmet_prerequisite(): void
    {
        $missing = $this->curricula['R']->missingPrerequisites([$this->subjects['A']->id]);

        $this->assertSame(['B'], $missing->pluck('code')->all());
    }

    public function test_or_logic_is_met_by_any_one_prerequisite(): void
    {
        $this->useOrLogic();
        $entry = $this->curricula['R']->fresh();

        $this->assertTrue($entry->missingPrerequisites([$this->subjects['B']->id])->isEmpty());
        $this->assertSame(['A', 'B'], $entry->missingPrerequisites([])->pluck('code')->all());
    }

    public function test_the_legacy_single_prerequisite_is_still_honoured(): void
    {
        $entry = $this->curricula['B'];
        $entry->update(['prerequisite' => $this->subjects['A']->id]);
        $entry = $entry->fresh();

        $this->assertSame(['A'], $entry->missingPrerequisites([])->pluck('code')->all());
        $this->assertTrue($entry->missingPrerequisites([$this->subjects['A']->id])->isEmpty());
    }

    public function test_an_entry_without_prerequisites_has_none_missing(): void
    {
        $this->assertTrue($this->curricula['A']->missingPrerequisites([])->isEmpty());
    }

    // ---------------------------------------------------------------- retakes

    public function test_retake_honours_or_prerequisites(): void
    {
        $this->useOrLogic();
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 2.00, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 5.00, 'Failed');
        $this->recordGrade($this->student, 'R', '2026-2027', 1, 5.00, 'Failed');

        // Previously refused with "B must be completed" despite A satisfying the OR.
        $this->assertSame([], $this->retakeErrors());
    }

    public function test_retake_still_requires_and_prerequisites(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 2.00, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 5.00, 'Failed');
        $this->recordGrade($this->student, 'R', '2026-2027', 1, 5.00, 'Failed');

        $this->assertSame(['B must be completed (Passed/Credited) before enrolling in R.'], $this->retakeErrors());
    }

    // ------------------------------------------------------------ load limits

    public function test_load_counts_a_retake_whose_or_prerequisite_is_met(): void
    {
        $this->useOrLogic();
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 2.00, 'Passed');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 5.00, 'Failed');
        $this->recordGrade($this->student, 'R', '2026-2027', 1, 5.00, 'Failed');

        // B (3 units, retake) + R (3 units, retake, OR met by A).
        $this->assertSame(6, $this->maxEligibleUnits());
    }

    public function test_load_respects_a_legacy_single_prerequisite(): void
    {
        $this->curricula['R']->prerequisites()->detach();
        $this->curricula['R']->update(['prerequisite' => $this->subjects['A']->id]);
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 5.00, 'Failed');
        $this->recordGrade($this->student, 'R', '2026-2027', 1, 5.00, 'Failed');

        // Only A counts: R still needs A, which the old load check ignored.
        $this->assertSame(3, $this->maxEligibleUnits());
    }

    public function test_load_recognises_a_remarks_only_legacy_pass(): void
    {
        // A row from before the status column existed: outcome in remarks only.
        Grade::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['A']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'grade_value' => null, 'status' => null, 'remarks' => 'PASSED',
        ]);
        $this->recordGrade($this->student, 'B', '2026-2027', 1, 2.00, 'Passed');
        $this->recordGrade($this->student, 'R', '2026-2027', 1, 5.00, 'Failed');

        $this->assertSame(3, $this->maxEligibleUnits());
    }

    // ------------------------------------------------------------- E3: pickers

    public function test_curriculum_endpoint_lists_every_prerequisite(): void
    {
        $curriculum = collect(
            $this->getJson("/api/staff/programs/{$this->program->id}/curriculum")->assertOk()->json('curriculum')
        )->keyBy(fn ($row) => $row['subject']['code']);

        $this->assertSame(['A', 'B'], array_column($curriculum['R']['prerequisites'], 'code'));
        $this->assertSame([], $curriculum['A']['prerequisites']);
    }

    public function test_available_subjects_carry_prerequisite_codes_and_logic(): void
    {
        $this->useOrLogic();

        $r = $this->availableSubject($this->academicProgress($this->student), 'R');

        $this->assertSame(['A', 'B'], $r['prerequisite_codes']);
        $this->assertSame('OR', $r['prerequisite_logic']);
        $this->assertSame('AND', $this->availableSubject($this->academicProgress($this->student), 'A')['prerequisite_logic']);
    }
}
