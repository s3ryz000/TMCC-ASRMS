<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\OfficialTranscriptExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #28: curriculum changes never rewrite student history. An entry whose
 * subject already has an enrollment (any status, soft-deleted too) or a grade
 * from a student of the program is not moved or removed (409); placing new
 * subjects is always allowed, and existing records are never re-validated or
 * changed by an edit.
 */
class CurriculumGuardrailsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private Program $program;
    private int $studentSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');

        // Y1S1: GEC4, TPC1   Y1S2: TPC2 (needs TPC1)   Y4S1: TPC40, untouched by students
        $this->makeCurriculum($this->program, [
            'GEC4'  => [1, 1],
            'TPC1'  => [1, 1],
            'TPC2'  => [1, 2],
            'TPC40' => [4, 1],
        ], ['TPC2' => ['TPC1']]);

        SystemSetting::setValue('academic_year', '2026-2027');
        Sanctum::actingAs($this->staff, ['*']);
    }

    private function student(?Program $program = null, string $lastName = 'Dela Cruz'): Student
    {
        $n = ++$this->studentSeq;

        return $this->makeStudent($program ?? $this->program, [
            'student_number' => sprintf('2026-%04d', $n),
            'email'          => "student{$n}@tmcc.test",
            'last_name'      => $lastName,
            'first_name'     => "Student{$n}",
        ]);
    }

    /** A student enrolled in Y1S1 through the guided flow and graded. */
    private function gradedStudent(): Student
    {
        $student = $this->student();
        $this->enrollNextTerm($student, ['GEC4', 'TPC1'])->assertSuccessful();
        $this->submitGrades($student, ['GEC4' => 1.75, 'TPC1' => 2.0])->assertSuccessful();

        return $student;
    }

    private function move(string $code, int $year, int $semester)
    {
        return $this->patchJson("/api/staff/curriculum/{$this->curricula[$code]->id}", ['year_level' => $year, 'semester' => $semester]);
    }

    private function remove(string $code)
    {
        return $this->deleteJson("/api/staff/curriculum/{$this->curricula[$code]->id}");
    }

    private function assertEntryAt(string $code, int $year, int $semester): void
    {
        $this->assertDatabaseHas('curriculum', ['id' => $this->curricula[$code]->id, 'year_level' => $year, 'semester' => (string) $semester]);
    }

    // ------------------------------------------------------------ blocks

    public function test_an_entry_with_grades_cannot_be_moved_or_removed(): void
    {
        $this->gradedStudent();
        $logs = DB::table('system_logs')->count();
        $message = "1 student already has records for GEC4 in BSTM; it can't be moved or removed.";

        $this->move('GEC4', 2, 1)
            ->assertStatus(409)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('impact.students.count', 1)
            ->assertJsonPath('impact.can_move', false);
        $this->remove('GEC4')->assertStatus(409)->assertJsonPath('message', $message);

        $this->assertEntryAt('GEC4', 1, 1);
        $this->assertSame($logs, DB::table('system_logs')->count());
    }

    public function test_the_message_counts_every_student_with_records(): void
    {
        foreach (range(1, 12) as $i) {
            $this->recordGrade($this->student(), 'TPC1', '2026-2027', 1, 2.0, 'Passed');
        }

        $this->move('TPC1', 1, 2)
            ->assertStatus(409)
            ->assertJsonPath('message', "12 students already have records for TPC1 in BSTM; it can't be moved or removed.");
    }

    public function test_cancelled_and_soft_deleted_enrollments_count_as_records(): void
    {
        $cancelled = $this->student();
        Enrollment::create([
            'student_id' => $cancelled->student_id, 'subject_id' => $this->subjects['TPC2']->id,
            'academic_year' => '2026-2027', 'semester' => '2', 'year_level' => 1, 'status' => 'Cancelled',
        ]);
        $this->move('TPC2', 2, 1)->assertStatus(409);

        $deleted = $this->student();
        Enrollment::create([
            'student_id' => $deleted->student_id, 'subject_id' => $this->subjects['GEC4']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'year_level' => 1, 'status' => 'Enrolled',
        ])->delete();
        $this->assertSame(0, Enrollment::where('subject_id', $this->subjects['GEC4']->id)->count());

        $this->remove('GEC4')
            ->assertStatus(409)
            ->assertJsonPath('impact.enrollments.soft_deleted', 1);
        $this->assertEntryAt('GEC4', 1, 1);
    }

    public function test_a_grade_without_an_enrollment_counts_as_a_record(): void
    {
        $this->recordGrade($this->student(), 'TPC40', '2026-2027', 1, null, 'Credited');

        $this->remove('TPC40')->assertStatus(409);
        $this->assertEntryAt('TPC40', 4, 1);
    }

    public function test_another_programs_students_do_not_block(): void
    {
        $other = $this->makeProgram('BSHM', 'BS Hospitality Management');
        $this->recordGrade($this->student($other), 'GEC4', '2026-2027', 1, 1.5, 'Passed');

        $this->move('GEC4', 1, 2)->assertOk();
        $this->assertEntryAt('GEC4', 1, 2);
    }

    public function test_a_subject_another_entry_requires_cannot_be_removed(): void
    {
        $this->remove('TPC1')
            ->assertStatus(409)
            ->assertJsonPath('message', "TPC1 can't be removed from BSTM: TPC2 lists it as a prerequisite.");

        // It can still be moved when no student has records for it.
        $this->move('TPC1', 1, 2)->assertOk();
    }

    // ----------------------------------------------------------- allowed

    public function test_entries_without_records_move_and_remove(): void
    {
        $this->gradedStudent();

        $this->move('TPC40', 3, 2)->assertOk();
        $this->assertEntryAt('TPC40', 3, 2);

        $this->remove('TPC40')->assertOk();
        $this->assertDatabaseMissing('curriculum', ['id' => $this->curricula['TPC40']->id]);
    }

    public function test_placing_is_always_allowed(): void
    {
        $this->gradedStudent();
        $subject = Subject::create(['code' => 'TPC41', 'title' => 'Tourism Research', 'units' => 3]);

        $this->postJson("/api/staff/programs/{$this->program->id}/curriculum", ['subject_id' => $subject->id, 'year_level' => 1, 'semester' => 1])
            ->assertCreated();
    }

    // ------------------------------------------------------------ impact

    public function test_impact_reports_students_statuses_and_dependents(): void
    {
        $graded = $this->gradedStudent();
        $failed = $this->student(null, 'Abad');
        $this->recordGrade($failed, 'TPC1', '2025-2026', 1, 5.0, 'Failed');
        $other = $this->makeProgram('BSHM', 'BS Hospitality Management');
        $this->recordGrade($this->student($other), 'TPC1', '2026-2027', 1, 1.0, 'Passed');

        $this->getJson("/api/staff/curriculum/{$this->curricula['TPC1']->id}/impact")
            ->assertOk()
            ->assertExactJson([
                'entry' => [
                    'entry_id' => $this->curricula['TPC1']->id, 'subject_id' => $this->subjects['TPC1']->id,
                    'code' => 'TPC1', 'title' => 'Subject TPC1', 'year_level' => 1, 'semester' => 1, 'program' => 'BSTM',
                ],
                'students' => [
                    'count' => 2,
                    'shown' => [
                        ['student_id' => $failed->student_id, 'student_number' => $failed->student_number, 'name' => "Abad, {$failed->first_name}"],
                        ['student_id' => $graded->student_id, 'student_number' => $graded->student_number, 'name' => "Dela Cruz, {$graded->first_name}"],
                    ],
                ],
                'enrollments' => ['total' => 1, 'by_status' => ['Passed' => 1], 'soft_deleted' => 0],
                'grades'      => ['total' => 2, 'by_status' => ['Failed' => 1, 'Passed' => 1]],
                'required_by' => [[
                    'entry_id' => $this->curricula['TPC2']->id, 'subject_id' => $this->subjects['TPC2']->id,
                    'code' => 'TPC2', 'title' => 'Subject TPC2', 'year_level' => 1, 'semester' => 2,
                ]],
                'can_move'   => false,
                'can_remove' => false,
            ]);

        $this->getJson("/api/staff/curriculum/{$this->curricula['TPC40']->id}/impact")
            ->assertOk()
            ->assertJsonPath('students.count', 0)
            ->assertJsonPath('enrollments.by_status', [])
            ->assertJsonPath('can_move', true)
            ->assertJsonPath('can_remove', true);
    }

    public function test_impact_lists_at_most_20_students(): void
    {
        foreach (range(1, 22) as $i) {
            $this->recordGrade($this->student(), 'GEC4', '2026-2027', 1, 2.0, 'Passed');
        }

        $this->getJson("/api/staff/curriculum/{$this->curricula['GEC4']->id}/impact")
            ->assertOk()
            ->assertJsonPath('students.count', 22)
            ->assertJsonCount(20, 'students.shown');
    }

    public function test_impact_is_readable_by_admin_only_besides_staff(): void
    {
        $url = "/api/staff/curriculum/{$this->curricula['GEC4']->id}/impact";

        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->getJson($url)->assertOk();
        $this->getJson('/api/staff/curriculum/9999/impact')->assertNotFound();

        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $this->getJson($url)->assertForbidden();
    }

    // ---------------------------------------------------------- snapshot

    public function test_a_permitted_edit_leaves_student_history_byte_identical(): void
    {
        $student = $this->gradedStudent();
        // Y1S2 in progress: enrolled, not yet graded.
        $this->enrollNextTerm($student, ['TPC2'])->assertSuccessful();

        // Both progress responses also carry a plan of the subjects still to
        // take, each shown at its curriculum term (residency's remaining
        // subjects, the summary's roadmap). That plan is the curriculum, not
        // history, so it is split out and checked separately.
        $snapshot = function () use ($student) {
            $transcript = new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml');
            $progress = $this->getJson("/api/staff/students/{$student->student_id}/academic-progress")->assertOk()->json();
            $summary = $this->getJson("/api/staff/students/{$student->student_id}/academic-summary")->assertOk()->json();
            $plan = [
                'remaining' => $progress['residency']['remaining_required_subjects'],
                'roadmap'   => $summary['curriculum']['roadmap'],
            ];
            unset($progress['residency']['remaining_required_subjects'], $summary['curriculum']['roadmap']);

            return [
                'transcript'  => $transcript->invoke(app(OfficialTranscriptExportService::class), $student->fresh(), 'DOC', 'NOW'),
                'progress'    => json_encode($progress),
                'standing'    => json_encode($summary),
                'enrollments' => Enrollment::withTrashed()->orderBy('id')->get()->toJson(),
                'grades'      => Grade::orderBy('id')->get()->toJson(),
                'plan'        => $plan,
            ];
        };

        $before = $snapshot();
        $this->assertStringContainsString('"overall_gwa":1.88', $before['standing']);
        $this->assertStringContainsString('"grouped_enrollments"', $before['progress']);
        $this->assertStringContainsString('TPC1', $before['transcript']);

        // A permitted edit in the same program: TPC40 has no records.
        $this->move('TPC40', 3, 2)->assertOk();

        $after = $snapshot();
        foreach (['transcript', 'progress', 'standing', 'enrollments', 'grades'] as $part) {
            $this->assertSame($before[$part], $after[$part], "{$part} changed after a permitted curriculum edit");
        }

        // In the plan, and only there, TPC40 now shows at Y3 S2.
        $expected = $before['plan'];
        foreach ($expected['remaining'] as &$row) {
            if ($row['subject_code'] === 'TPC40') {
                [$row['year_level'], $row['semester']] = [3, '2'];
            }
        }
        foreach ($expected['roadmap'] as &$row) {
            if ($row['subject_code'] === 'TPC40') {
                [$row['curriculum_year_level'], $row['curriculum_semester']] = [3, '2'];
            }
        }
        unset($row);
        $this->assertSame($expected, $after['plan']);

        // Placing and removing never touch existing records either.
        $new = Subject::create(['code' => 'TPC41', 'title' => 'Tourism Research', 'units' => 3]);
        $entryId = $this->postJson("/api/staff/programs/{$this->program->id}/curriculum", ['subject_id' => $new->id, 'year_level' => 4, 'semester' => 2])
            ->assertCreated()->json('entry.id');
        $this->deleteJson("/api/staff/curriculum/{$entryId}")->assertOk();
        $this->remove('TPC40')->assertOk();

        $final = $snapshot();
        foreach (['transcript', 'enrollments', 'grades'] as $part) {
            $this->assertSame($before[$part], $final[$part], "{$part} changed after placing or removing an entry");
        }
        // GWA, honours and term GPAs stay; the totals beside them follow the
        // curriculum (TPC40 removed: 9 units instead of 12).
        $this->assertSame(json_decode($before['standing'], true)['summary'], json_decode($final['standing'], true)['summary']);
        $this->assertSame(9, json_decode($final['standing'], true)['curriculum']['total_curriculum_units']);
    }
}
