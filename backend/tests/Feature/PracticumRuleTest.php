<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Services\AcademicProgressionService;
use App\Services\Enrollment\Rules\ProgramCompletionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #82: PRACTICUM (any curriculum row marked requires_all_other_subjects) can
 * be enrolled only once every other subject of the student's program is
 * Passed or Credited. New enrollments only; existing ones are never
 * re-validated (#28).
 */
class PracticumRuleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const BLOCKED = 'PRACTICUM can be taken after all other subjects in BSTM are passed (2 remaining: B, C).';

    private Program $program;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($this->program, [
            'A'         => [1, 1],
            'B'         => [1, 1],
            'C'         => [1, 2],
            'PRACTICUM' => [4, 2],
        ]);
        $this->curricula['PRACTICUM']->update(['requires_all_other_subjects' => true]);
        $this->student = $this->makeStudent($this->program);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function grade(string $code, string $status = 'Passed', ?float $value = 2.00): void
    {
        Grade::create([
            'student_id'    => $this->student->student_id,
            'subject_id'    => $this->subjects[$code]->id,
            'academic_year' => '2026-2027',
            'semester'      => 1,
            'grade_value'   => $value,
            'status'        => $status,
        ]);
    }

    private function enrollPracticum()
    {
        return $this->postJson("/api/staff/students/{$this->student->student_id}/enrollments", [
            'academic_year' => '2029-2030',
            'semester'      => '2nd',
            'year_level'    => 4,
            'subject_ids'   => [$this->subjects['PRACTICUM']->id],
        ]);
    }

    private function practicumInPicker(): array
    {
        $rows = app(AcademicProgressionService::class)->getAvailableSubjects($this->student->fresh(), 4, 2);

        return collect($rows)->firstWhere('subject_code', 'PRACTICUM');
    }

    // ── Enrollment ──────────────────────────────────────────────────────────

    public function test_practicum_is_refused_while_other_subjects_remain_and_lists_them(): void
    {
        $this->grade('A');
        $this->grade('B', 'Failed', 5.00);

        $response = $this->enrollPracticum()->assertStatus(422);

        $this->assertStringContainsString(self::BLOCKED, json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Enrollment::count());
    }

    public function test_practicum_is_allowed_once_every_other_subject_is_passed_or_credited(): void
    {
        $this->grade('A');
        $this->grade('B', 'Credited', null);
        $this->grade('C');

        $this->enrollPracticum()->assertStatus(201);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $this->student->student_id,
            'subject_id' => $this->subjects['PRACTICUM']->id,
            'status'     => 'Enrolled',
        ]);
    }

    public function test_the_next_term_picker_shows_why_practicum_is_blocked(): void
    {
        $this->grade('A');

        $row = $this->practicumInPicker();
        $this->assertFalse($row['eligible']);
        $this->assertTrue($row['requires_all_other_subjects']);
        $this->assertSame(self::BLOCKED, $row['blocked_reason']);

        $this->grade('B');
        $this->grade('C');

        $row = $this->practicumInPicker();
        $this->assertTrue($row['eligible']);
        $this->assertNull($row['blocked_reason']);
    }

    public function test_the_curriculum_view_shows_the_rule(): void
    {
        $roadmap = collect(app(AcademicProgressionService::class)->getCurriculumRoadmap($this->student->fresh())['roadmap'] ?? [])
            ->keyBy('subject_code');

        $this->assertSame('After all other subjects', $roadmap['PRACTICUM']['prerequisites']);
        $this->assertSame('Blocked - Missing Prerequisite', $roadmap['PRACTICUM']['status']);
        $this->assertSame('', $roadmap['A']['prerequisites']);

        $this->getJson("/api/staff/programs/{$this->program->id}/curriculum")
            ->assertOk()
            ->assertJsonFragment(['requires_all_other_subjects' => true]);
    }

    // ── Existing records (#28) ──────────────────────────────────────────────

    public function test_an_existing_practicum_enrollment_is_never_revalidated(): void
    {
        // Enrolled before the rule existed, with B and C still unfinished.
        Enrollment::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['PRACTICUM']->id,
            'academic_year' => '2029-2030', 'semester' => '2', 'year_level' => 4, 'status' => 'Enrolled',
        ]);
        $this->grade('PRACTICUM', 'Enrolled', null);

        // Other work on the record still goes through, and PRACTICUM can be graded.
        $this->postJson("/api/staff/students/{$this->student->student_id}/enrollments", [
            'academic_year' => '2026-2027', 'semester' => '1st', 'year_level' => 1,
            'subject_ids' => [$this->subjects['A']->id],
        ])->assertStatus(201);
        $this->submitGrades($this->student, ['PRACTICUM' => 1.50])->assertOk();

        $this->assertDatabaseHas('enrollments', ['subject_id' => $this->subjects['PRACTICUM']->id, 'deleted_at' => null]);
        $this->assertEquals(1.50, Grade::where('subject_id', $this->subjects['PRACTICUM']->id)->latest('id')->value('grade_value'));
    }

    // ── Who is waited for ───────────────────────────────────────────────────

    public function test_archived_and_other_completion_subjects_are_not_waited_for(): void
    {
        $this->makeCurriculum($this->program, ['D' => [2, 1], 'CAPSTONE' => [4, 2]]);
        $this->curricula['CAPSTONE']->update(['requires_all_other_subjects' => true]);
        $this->subjects['D']->forceFill(['archived_at' => now()])->save();

        $entries = Curriculum::with('subject')->where('program_id', $this->program->id)->get();
        $passed = [$this->subjects['A']->id, $this->subjects['B']->id];

        $this->assertSame(['C'], ProgramCompletionRule::remaining($entries, $this->subjects['PRACTICUM']->id, $passed));
    }

    public function test_a_long_list_is_shortened(): void
    {
        $codes = array_map(fn ($i) => "S{$i}", range(1, 12));

        $this->assertSame(
            'PRACTICUM can be taken after all other subjects in BSHM are passed (12 remaining: S1, S2, S3, S4, S5, S6, S7, S8, S9, S10 and 2 more).',
            ProgramCompletionRule::message('PRACTICUM', 'BSHM', $codes),
        );
    }

    // ── Curriculum Builder ──────────────────────────────────────────────────

    public function test_the_registrar_can_set_and_clear_the_rule_in_the_builder(): void
    {
        $entry = $this->curricula['C'];

        $this->putJson("/api/staff/curriculum/{$entry->id}/prerequisites", [
            'subject_ids' => [], 'requires_all_other_subjects' => true,
        ])->assertOk()->assertJsonPath('message', 'C can be taken after all other subjects of the program are passed.');
        $this->assertTrue($entry->fresh()->requires_all_other_subjects);
        $this->assertDatabaseHas('system_logs', ['action' => 'Curriculum: BSTM C prerequisites cleared; after all other subjects']);

        // Leaving the field out keeps it.
        $this->putJson("/api/staff/curriculum/{$entry->id}/prerequisites", ['subject_ids' => [$this->subjects['A']->id]])->assertOk();
        $this->assertTrue($entry->fresh()->requires_all_other_subjects);

        $this->putJson("/api/staff/curriculum/{$entry->id}/prerequisites", [
            'subject_ids' => [], 'requires_all_other_subjects' => false,
        ])->assertOk();
        $this->assertFalse($entry->fresh()->requires_all_other_subjects);
    }

    // ── Seeded data and the migration ───────────────────────────────────────

    // Seeding is covered in SeededPrerequisitesTest, which starts from an empty database.

    public function test_the_migration_marks_existing_practicum_rows_and_changes_nothing_else(): void
    {
        $bshm = $this->makeProgram('BSHM', 'BS Hospitality Management');
        $bse = $this->makeProgram('BSE', 'BS Entrepreneurship');
        foreach ([$bshm, $bse] as $program) {
            Curriculum::create(['program_id' => $program->id, 'subject_id' => $this->subjects['PRACTICUM']->id, 'year_level' => 4, 'semester' => 2]);
        }

        $migration = require database_path('migrations/2026_10_07_000001_add_requires_all_other_subjects_to_curriculum.php');
        $migration->down();
        $rows = DB::table('curriculum')->count();
        $migration->up();

        $this->assertSame($rows, DB::table('curriculum')->count());
        $marked = DB::table('curriculum')->join('programs', 'programs.id', '=', 'curriculum.program_id')
            ->where('requires_all_other_subjects', true)->pluck('programs.code')->sort()->values()->all();
        $this->assertSame(['BSHM', 'BSTM'], $marked);
        $this->assertSame(4, DB::table('curriculum')->where('program_id', $this->program->id)->where('requires_all_other_subjects', false)->count() + 1);
    }
}
