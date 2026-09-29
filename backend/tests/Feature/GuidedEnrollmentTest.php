<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Path B: the guided next-term flow (POST …/enrollments/add-next-term). This
 * is the enrollment path the Edit Student page actually calls, so it carries
 * the prerequisite, INC/Failed, retake and academic-load rules end to end.
 */
class GuidedEnrollmentTest extends TestCase
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

        // Y1S1: A, B      Y1S2: C (needs A), D (needs B), E
        // Y2S1: F (needs C)
        $this->makeCurriculum($this->program, [
            'A' => [1, 1],
            'B' => [1, 1],
            'C' => [1, 2],
            'D' => [1, 2],
            'E' => [1, 2],
            'F' => [2, 1],
        ], [
            'C' => ['A'],
            'D' => ['B'],
            'F' => ['C'],
        ]);

        $this->student = $this->makeStudent($this->program);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    /** Enroll and grade the first term in one step. */
    private function completeFirstTerm(array $grades): void
    {
        $this->enrollNextTerm($this->student, array_keys($grades))->assertCreated();
        $this->submitGrades($this->student, $grades)->assertOk();
    }

    // ------------------------------------------------------ term sequencing

    public function test_a_new_student_starts_at_year_1_semester_1(): void
    {
        $next = $this->academicProgress($this->student)['next_allowed_term'];

        $this->assertTrue($next['can_add']);
        $this->assertSame(1, $next['year_level']);
        $this->assertSame(1, $next['semester']);
        $this->assertSame('2026-2027', $next['academic_year']);
    }

    public function test_registrar_enrolls_the_first_term(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])
            ->assertCreated()
            ->assertJsonPath('enrolled_count', 2)
            ->assertJsonPath('retake_count', 0);

        foreach (['A', 'B'] as $code) {
            $this->assertDatabaseHas('enrollments', [
                'student_id'    => $this->student->student_id,
                'subject_id'    => $this->subjects[$code]->id,
                'academic_year' => '2026-2027',
                'semester'      => 1,
                'year_level'    => 1,
                'status'        => 'Enrolled',
                'is_retake'     => false,
            ]);
            $this->assertDatabaseHas('grades', [
                'student_id' => $this->student->student_id,
                'subject_id' => $this->subjects[$code]->id,
                'status'     => 'Enrolled',
            ]);
        }

        $this->assertSame(2, EnrollmentAuditLog::where('action', 'enrollment_created')->count());
    }

    public function test_next_term_waits_until_every_grade_is_final(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();

        $next = $this->academicProgress($this->student)['next_allowed_term'];
        $this->assertFalse($next['can_add']);
        $this->assertEqualsCanonicalizing(['A', 'B'], $next['incomplete_subjects']);

        $this->enrollNextTerm($this->student, ['C'])->assertStatus(422);
    }

    public function test_next_term_opens_once_grades_are_final(): void
    {
        $this->completeFirstTerm(['A' => 2.00, 'B' => 2.00]);

        $next = $this->academicProgress($this->student)['next_allowed_term'];

        $this->assertTrue($next['can_add']);
        $this->assertSame(1, $next['year_level']);
        $this->assertSame(2, $next['semester']);
    }

    public function test_subject_from_another_term_is_rejected(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'C'])
            ->assertStatus(422)
            ->assertJsonFragment(['C does not belong to the curriculum for Year 1, Semester 1.']);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_at_least_one_subject_is_required(): void
    {
        $this->enrollNextTerm($this->student, [])->assertStatus(422);
    }

    // ------------------------------------------------- prerequisites: INC / Failed

    public function test_passed_prerequisite_unlocks_the_dependent_subject(): void
    {
        $this->completeFirstTerm(['A' => 1.75, 'B' => 2.25]);

        $c = $this->availableSubject($this->academicProgress($this->student), 'C');
        $this->assertTrue($c['eligible']);
        $this->assertSame('passed', $c['prerequisite_status']);

        $this->enrollNextTerm($this->student, ['C', 'D', 'E'])->assertCreated();
    }

    public function test_inc_prerequisite_blocks_the_dependent_subject(): void
    {
        $this->completeFirstTerm(['A' => 'INC', 'B' => 2.00]);

        $progress = $this->academicProgress($this->student);
        $c = $this->availableSubject($progress, 'C');

        $this->assertFalse($c['eligible']);
        $this->assertSame('inc', $c['prerequisite_status']);
        $this->assertSame('A has INC status. Complete the prerequisite first.', $c['blocked_reason']);
        $this->assertTrue($this->availableSubject($progress, 'D')['eligible']);

        $this->enrollNextTerm($this->student, ['C'])
            ->assertStatus(422)
            ->assertJsonFragment(['A must be completed (Passed/Credited) before enrolling in C.']);
    }

    public function test_failed_prerequisite_blocks_the_dependent_subject(): void
    {
        $this->completeFirstTerm(['A' => 5.00, 'B' => 2.00]);

        $c = $this->availableSubject($this->academicProgress($this->student), 'C');

        $this->assertFalse($c['eligible']);
        $this->assertSame('failed', $c['prerequisite_status']);
        $this->assertSame('Required prerequisite: A.', $c['blocked_reason']);

        $this->enrollNextTerm($this->student, ['C'])->assertStatus(422);
        $this->assertSame(0, Enrollment::where('subject_id', $this->subjects['C']->id)->count());
    }

    public function test_inc_and_failed_do_not_count_toward_passed_subjects(): void
    {
        $this->completeFirstTerm(['A' => 'INC', 'B' => 5.00]);

        $progress = $this->academicProgress($this->student);

        $this->assertFalse($this->availableSubject($progress, 'C')['eligible']);
        $this->assertFalse($this->availableSubject($progress, 'D')['eligible']);
        $this->assertTrue($this->availableSubject($progress, 'E')['eligible']);
    }

    // --------------------------------------------------------------- retakes

    public function test_failed_subject_is_offered_as_a_retake_in_its_own_semester(): void
    {
        $this->completeFirstTerm(['A' => 5.00, 'B' => 2.00]);

        // Y1S2 is a 2nd semester; A is a 1st-semester subject, so no retake yet.
        $progress = $this->academicProgress($this->student);
        $this->assertSame([], array_column($progress['retake_subjects_available'], 'subject_id'));

        $this->enrollNextTerm($this->student, ['D', 'E'])->assertCreated();
        $this->submitGrades($this->student, ['D' => 2.00, 'E' => 2.00])->assertOk();

        // Y2S1 is a 1st semester: A is now offered.
        $progress = $this->academicProgress($this->student);
        $this->assertSame(2, $progress['next_allowed_term']['year_level']);
        $this->assertSame([$this->subjects['A']->id], array_column($progress['retake_subjects_available'], 'subject_id'));

        $this->enrollNextTerm($this->student, [], ['A'])
            ->assertCreated()
            ->assertJsonPath('retake_count', 1);

        $this->assertDatabaseHas('enrollments', [
            'subject_id'    => $this->subjects['A']->id,
            'academic_year' => '2027-2028',
            'semester'      => 1,
            'is_retake'     => true,
        ]);
        $this->assertSame(1, EnrollmentAuditLog::where('action', 'retake_enrollment_created')->count());
    }

    public function test_passing_the_retake_satisfies_the_prerequisite(): void
    {
        $this->completeFirstTerm(['A' => 5.00, 'B' => 2.00]);
        $this->enrollNextTerm($this->student, ['D', 'E'])->assertCreated();
        $this->submitGrades($this->student, ['D' => 2.00, 'E' => 2.00])->assertOk();
        $this->enrollNextTerm($this->student, [], ['A'])->assertCreated();

        $this->submitGrades($this->student, ['A' => 2.50])->assertOk();

        // The original Failed row is kept as history; the retake's Passed row counts.
        $this->assertSame(['Failed', 'Passed'], Grade::where('subject_id', $this->subjects['A']->id)
            ->orderBy('id')->pluck('status')->all());
        $this->assertContains(
            $this->subjects['A']->id,
            app(\App\Services\Enrollment\AcademicRecordQuery::class)->passedSubjectIds($this->student)
        );
    }

    public function test_inc_subject_cannot_be_retaken(): void
    {
        $this->completeFirstTerm(['A' => 'INC', 'B' => 2.00]);
        $this->enrollNextTerm($this->student, ['D', 'E'])->assertCreated();
        $this->submitGrades($this->student, ['D' => 2.00, 'E' => 2.00])->assertOk();

        $progress = $this->academicProgress($this->student);
        $this->assertSame([$this->subjects['A']->id], array_column($progress['inc_subjects'], 'subject_id'));

        $this->enrollNextTerm($this->student, [], ['A'])
            ->assertStatus(422)
            ->assertJsonFragment(['A has an INC status. Resolve INC before creating a retake.']);
    }

    // --------------------------------------------------------- academic load

    public function test_load_above_26_units_is_rejected(): void
    {
        $this->subjects['A']->update(['units' => 14]);
        $this->subjects['B']->update(['units' => 14]);

        $this->enrollNextTerm($this->student, ['A', 'B'])
            ->assertStatus(422)
            ->assertJsonPath('load_validation.load_status', 'above_maximum');

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_underload_is_rejected_when_more_eligible_units_exist(): void
    {
        $this->subjects['A']->update(['units' => 10]);
        $this->subjects['B']->update(['units' => 10]);

        $this->enrollNextTerm($this->student, ['A'])
            ->assertStatus(422)
            ->assertJsonPath('load_validation.load_status', 'below_minimum');
    }

    public function test_underload_is_allowed_when_the_curriculum_offers_less_than_18_units(): void
    {
        // A + B are only 6 units, so taking both is a valid underload.
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
    }
}
