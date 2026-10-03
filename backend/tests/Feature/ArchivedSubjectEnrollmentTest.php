<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #68: an archived subject cannot be added to any new enrollment, on any
 * path, while records that already contain it never change.
 */
class ArchivedSubjectEnrollmentTest extends TestCase
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

    private function archive(string $code): void
    {
        $this->subjects[$code]->forceFill(['archived_at' => now()])->save();
    }

    private function unarchive(string $code): void
    {
        $this->subjects[$code]->forceFill(['archived_at' => null])->save();
    }

    private function refusal(string $code): string
    {
        return "{$code} Subject {$code} is archived and can't be added to new enrollments.";
    }

    private function newStudentPayload(array $subjectIds): array
    {
        return [
            'student_number'  => '2026-9999',
            'first_name'      => 'Maria',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2005-05-05',
            'email'           => 'maria@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
            'program_id'      => $this->program->id,
            'subject_ids'     => $subjectIds,
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ];
    }

    // ----------------------------------------------- refused on every path

    public function test_new_student_cannot_start_with_an_archived_subject(): void
    {
        $this->archive('A');
        $before = Enrollment::count();

        $response = $this->postJson('/api/staff/students', $this->newStudentPayload($this->idsFor(['A', 'B'])))
            ->assertStatus(422);

        $this->assertStringContainsString($this->refusal('A'), json_encode($response->json()));
        $this->assertDatabaseMissing('students', ['student_number' => '2026-9999']);
        $this->assertDatabaseMissing('users', ['username' => '2026-9999']);
        $this->assertSame($before, Enrollment::count());

        $this->unarchive('A');
        $this->postJson('/api/staff/students', $this->newStudentPayload($this->idsFor(['A', 'B'])))->assertCreated();
    }

    public function test_add_next_term_refuses_an_archived_subject(): void
    {
        $this->archive('A');

        $response = $this->enrollNextTerm($this->student, ['A', 'B'])->assertStatus(422);

        $this->assertStringContainsString($this->refusal('A'), json_encode($response->json()));
        $this->assertSame(0, Enrollment::where('student_id', $this->student->student_id)->count());

        $this->unarchive('A');
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated()->assertJsonPath('enrolled_count', 2);
    }

    public function test_manual_enrollment_refuses_an_archived_subject(): void
    {
        $this->archive('A');
        $payload = ['academic_year' => '2026-2027', 'semester' => '1st', 'year_level' => 1, 'subject_ids' => $this->idsFor(['A'])];
        $url = "/api/staff/students/{$this->student->student_id}/enrollments";

        $response = $this->postJson($url, $payload)->assertStatus(422);

        $this->assertStringContainsString($this->refusal('A'), json_encode($response->json()));
        $this->assertSame(0, Enrollment::where('student_id', $this->student->student_id)->count());

        $this->unarchive('A');
        $this->postJson($url, $payload)->assertCreated();
    }

    public function test_a_retake_of_an_archived_subject_is_refused(): void
    {
        // A failed in Y1S1; a 1st-semester retake is offered in Y2S1.
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 5.00, 'B' => 2.00])->assertOk();
        $this->enrollNextTerm($this->student, ['D', 'E'])->assertCreated();
        $this->submitGrades($this->student, ['D' => 2.00, 'E' => 2.00])->assertOk();

        $this->archive('A');
        $response = $this->enrollNextTerm($this->student, [], ['A'])->assertStatus(422);

        $this->assertStringContainsString($this->refusal('A'), json_encode($response->json()));
        $this->assertSame(0, Enrollment::where('is_retake', true)->count());

        $this->unarchive('A');
        $this->enrollNextTerm($this->student, [], ['A'])->assertCreated()->assertJsonPath('retake_count', 1);
    }

    // ---------------------------------------- existing records are untouched

    public function test_an_existing_enrollment_can_still_be_moved_after_its_subject_is_archived(): void
    {
        $enrollment = Enrollment::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['A']->id,
            'academic_year' => '2026-2027', 'semester' => 1, 'year_level' => 1, 'status' => 'Enrolled',
        ]);
        $this->archive('A');

        $this->putJson(
            "/api/staff/students/{$this->student->student_id}/enrollments/{$enrollment->id}",
            ['academic_year' => '2027-2028', 'semester' => '1st']
        )->assertOk();

        $this->assertSame('2027-2028', $enrollment->fresh()->academic_year);
    }

    // ------------------------------------------------ suggestions and views

    public function test_next_term_suggestions_leave_archived_subjects_out(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 2.00, 'B' => 2.00])->assertOk();

        $before = $this->academicProgress($this->student);
        $this->assertContains($this->subjects['E']->id, array_column($before['available_subjects'], 'subject_id'));

        $this->archive('E');
        $after = $this->academicProgress($this->student);

        $this->assertNotContains($this->subjects['E']->id, array_column($after['available_subjects'], 'subject_id'));
        $this->assertSame($before['max_eligible_units'] - 3, $after['max_eligible_units']);
        // Nothing else about the term changes.
        $this->assertSame($before['next_allowed_term'], $after['next_allowed_term']);
    }

    public function test_an_archived_failed_subject_is_not_offered_as_a_retake_but_stays_required(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 5.00, 'B' => 2.00])->assertOk();
        $this->enrollNextTerm($this->student, ['D', 'E'])->assertCreated();
        $this->submitGrades($this->student, ['D' => 2.00, 'E' => 2.00])->assertOk();
        $this->assertSame([$this->subjects['A']->id], array_column($this->academicProgress($this->student)['retake_subjects_available'], 'subject_id'));

        $this->archive('A');
        $progress = $this->academicProgress($this->student);

        $this->assertSame([], array_column($progress['retake_subjects_available'], 'subject_id'));
        $this->assertSame([$this->subjects['A']->id], array_column($progress['retake_subjects_required'], 'subject_id'));
    }

    public function test_program_curriculum_keeps_archived_subjects_and_marks_them(): void
    {
        $this->archive('C');

        $rows = collect($this->getJson("/api/staff/programs/{$this->program->id}/curriculum")->assertOk()->json('curriculum'))
            ->keyBy('subject.code');

        $this->assertCount(6, $rows);
        $this->assertTrue($rows['C']['subject']['archived']);
        $this->assertFalse($rows['A']['subject']['archived']);
        $this->assertSame(['A'], array_column($rows['C']['prerequisites'], 'code'));
    }

    public function test_the_student_curriculum_keeps_archived_subjects_and_marks_them(): void
    {
        $account = $this->makeUser('student', '2026-0002');
        $this->makeStudent($this->program, ['student_number' => '2026-0002', 'email' => 'second@tmcc.test'], $account);
        $this->archive('C');
        Sanctum::actingAs($account, ['*']);

        $rows = collect($this->getJson('/api/student/curriculum')->assertOk()->json('curriculum'))->keyBy('subject.code');

        $this->assertCount(6, $rows);
        $this->assertTrue($rows['C']['subject']['archived']);
        $this->assertFalse($rows['B']['subject']['archived']);
    }

    public function test_a_students_existing_grade_gwa_and_transcript_do_not_change(): void
    {
        $account = $this->makeUser('student', '2026-0003');
        $student = $this->makeStudent($this->program, ['student_number' => '2026-0003', 'email' => 'third@tmcc.test'], $account);
        $this->enrollNextTerm($student, ['A', 'B'])->assertCreated();
        $this->submitGrades($student, ['A' => 1.50, 'B' => 2.25])->assertOk();

        $snapshot = function () use ($account, $student) {
            Sanctum::actingAs($account, ['*']);
            $grades = $this->getJson('/api/student/grades')->assertOk()->json();
            $summary = $this->getJson('/api/student/academic-summary')->assertOk()->json();
            $html = (new \ReflectionMethod(\App\Services\OfficialTranscriptExportService::class, 'buildHtml'))
                ->invoke(app(\App\Services\OfficialTranscriptExportService::class), $student->fresh(['program', 'grades.subject']), 'TOR-TEST', 'now');

            return [$grades, $summary, $html];
        };

        [$gradesBefore, $summaryBefore, $htmlBefore] = $snapshot();
        // Always let the clock move, so the subject's updated_at differs on
        // every run instead of only sometimes.
        $this->travel(2)->seconds();
        $this->archive('A');
        [$gradesAfter, $summaryAfter, $htmlAfter] = $snapshot();

        // Archiving changes the embedded subject row's own archived_at and
        // updated_at (the latter only when the clock ticks over a second); the
        // student's record itself (grade, units, status, term) is identical.
        $withoutArchivedAt = function ($data) use (&$withoutArchivedAt) {
            if (! is_array($data)) {
                return $data;
            }
            unset($data['archived_at']);
            if (isset($data['subject']) && is_array($data['subject'])) {
                unset($data['subject']['updated_at']);
            }

            return array_map($withoutArchivedAt, $data);
        };
        $this->assertStringContainsString('Subject A', json_encode($gradesAfter));
        $this->assertStringContainsString('"archived_at":"', json_encode($gradesAfter));
        $this->assertSame($withoutArchivedAt($gradesBefore), $withoutArchivedAt($gradesAfter));
        $this->assertSame($summaryBefore, $summaryAfter);
        $this->assertSame($htmlBefore, $htmlAfter);
        $this->assertStringContainsString('Subject A', $htmlAfter);
    }

    public function test_grades_in_an_archived_subject_can_still_be_corrected(): void
    {
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $this->archive('A');

        $this->submitGrades($this->student, ['A' => 1.75, 'B' => 2.00])->assertOk();

        $this->assertSame('Passed', Grade::where('subject_id', $this->subjects['A']->id)->value('status'));
    }
}
