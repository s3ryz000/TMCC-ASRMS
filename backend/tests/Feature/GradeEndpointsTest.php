<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Services\Enrollment\AcademicRecordQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * The single-grade endpoints must apply the same rules as bulk grade entry:
 * a status derived from the value, the Credited document rule, the enrollment
 * row kept in step, and an audit row that records the real previous status.
 */
class GradeEndpointsTest extends TestCase
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
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1]]);
        $this->student = $this->makeStudent($this->program);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function gradeUrl(?Grade $grade = null): string
    {
        return "/api/staff/students/{$this->student->student_id}/grades" . ($grade ? "/{$grade->id}" : '');
    }

    private function enrolledGrade(string $code = 'A'): Grade
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();

        return Grade::where('subject_id', $this->subjects[$code]->id)->firstOrFail();
    }

    // ---------------------------------------------------------- single update

    public function test_correcting_the_value_re_derives_the_status(): void
    {
        $grade = $this->enrolledGrade();
        $this->putJson($this->gradeUrl($grade), ['grade_value' => 5.00])->assertOk();
        $this->assertSame('Failed', $grade->fresh()->status);

        $this->putJson($this->gradeUrl($grade), ['grade_value' => 2.00])
            ->assertOk()
            ->assertJsonPath('grade.status', 'Passed')
            ->assertJsonPath('grade.remarks', 'Passed');

        $this->assertContains($this->subjects['A']->id, app(AcademicRecordQuery::class)->passedSubjectIds($this->student));
    }

    public function test_an_explicit_status_is_kept(): void
    {
        $grade = $this->enrolledGrade();

        $this->putJson($this->gradeUrl($grade), ['status' => 'INC'])
            ->assertOk()
            ->assertJsonPath('grade.status', 'INC');
    }

    public function test_the_enrollment_status_follows_the_grade(): void
    {
        $grade = $this->enrolledGrade();

        $this->putJson($this->gradeUrl($grade), ['grade_value' => 5.00])->assertOk();

        $this->assertSame('Failed', Enrollment::findOrFail($grade->enrollment_id)->status);
    }

    public function test_the_audit_row_records_the_previous_status(): void
    {
        $grade = $this->enrolledGrade();
        $this->putJson($this->gradeUrl($grade), ['status' => 'INC'])->assertOk();

        $this->putJson($this->gradeUrl($grade), ['grade_value' => 2.00])->assertOk();

        $audit = EnrollmentAuditLog::where('subject_id', $this->subjects['A']->id)->latest('id')->firstOrFail();
        $this->assertSame('INC', $audit->old_status);
        $this->assertSame('Passed', $audit->new_status);
        $this->assertSame('inc_to_passed', $audit->action);
    }

    public function test_credited_needs_a_supporting_document(): void
    {
        $grade = $this->enrolledGrade();

        $this->putJson($this->gradeUrl($grade), ['status' => 'Credited', 'academic_year' => '2030-2031'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Refused as a whole: the term change in the same request is not saved either.
        $grade->refresh();
        $this->assertSame('Enrolled', $grade->status);
        $this->assertSame('2026-2027', $grade->academic_year);

        $this->putJson($this->gradeUrl($grade), ['status' => 'Credited', 'supporting_document_reference' => 'TOR-OLD-SCHOOL'])
            ->assertOk()
            ->assertJsonPath('grade.status', 'Credited');
    }

    public function test_unknown_status_is_rejected(): void
    {
        $grade = $this->enrolledGrade();

        $this->putJson($this->gradeUrl($grade), ['status' => 'Excellent'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_term_only_change_leaves_the_grade_alone(): void
    {
        $grade = $this->enrolledGrade();
        $this->putJson($this->gradeUrl($grade), ['grade_value' => 1.50])->assertOk();

        $this->putJson($this->gradeUrl($grade), ['semester' => '2'])->assertOk();

        $grade->refresh();
        $this->assertSame('2', $grade->semester);
        $this->assertSame('1.50', $grade->grade_value);
        $this->assertSame('Passed', $grade->status);
    }

    // ----------------------------------------------------------- single create

    public function test_a_new_grade_gets_a_status_from_its_value(): void
    {
        $this->postJson($this->gradeUrl(), [
            'subject_id'    => $this->subjects['B']->id,
            'academic_year' => '2026-2027',
            'semester'      => '1',
            'grade_value'   => 1.75,
        ])->assertCreated()
            ->assertJsonPath('grade.status', 'Passed')
            ->assertJsonPath('grade.remarks', 'Passed');
    }

    // ------------------------------------------------------------ bulk update

    public function test_bulk_audit_row_records_the_previous_status(): void
    {
        $this->enrolledGrade();
        $this->submitGrades($this->student, ['A' => 'INC'])->assertOk();

        $this->submitGrades($this->student, ['A' => 2.00])->assertOk();

        $audit = EnrollmentAuditLog::where('subject_id', $this->subjects['A']->id)->latest('id')->firstOrFail();
        $this->assertSame('INC', $audit->old_status);
        $this->assertSame('Passed', $audit->new_status);
    }

    // --------------------------------------------------------------- privacy

    public function test_viewing_a_record_does_not_log_personal_data(): void
    {
        Log::spy();

        $this->getJson("/api/staff/students/{$this->student->student_id}")->assertOk();

        Log::shouldNotHaveReceived('info');
    }
}
