<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #27: the registrar sets an entry's prerequisites (AND/OR) in the builder.
 * They must be earlier subjects of the same program and can't loop; saving
 * clears the seeder's unresolved prerequisites, applies to future
 * enrollments only, and is enforced exactly like seeded prerequisites.
 */
class PrerequisiteEditorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($this->program, [
            'GEC5' => [1, 1],
            'THC1' => [1, 1],
            'THC2' => [1, 2],
            'THC4' => [2, 1],
            'THC5' => [2, 1],
            'TPC9' => [3, 1],
        ]);
        SystemSetting::setValue('academic_year', '2026-2027');

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function setPrerequisites(string $code, array $codes, ?string $logic = 'AND')
    {
        return $this->putJson("/api/staff/curriculum/{$this->curricula[$code]->id}/prerequisites", array_filter([
            'subject_ids' => $this->idsFor($codes),
            'logic'       => $logic,
        ], fn ($v) => $v !== null));
    }

    private function prerequisiteCodes(string $code): array
    {
        return $this->curricula[$code]->fresh()->prerequisites->pluck('code')->sort()->values()->all();
    }

    // ------------------------------------------------------------- saving

    public function test_an_and_set_is_saved_and_logged(): void
    {
        $this->setPrerequisites('THC4', ['THC1', 'GEC5'])
            ->assertOk()
            ->assertJsonPath('message', 'THC4 now requires GEC5 and THC1.')
            ->assertJsonPath('entry.prerequisite_logic', 'AND')
            ->assertJsonCount(2, 'entry.prerequisites');

        $this->assertSame(['GEC5', 'THC1'], $this->prerequisiteCodes('THC4'));
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Curriculum: BSTM THC4 prerequisites set to GEC5 AND THC1', 'user_id' => $this->staff->id, 'role' => 'staff',
        ]);
    }

    public function test_an_or_set_is_saved_and_replaces_the_old_one(): void
    {
        $this->setPrerequisites('THC4', ['THC1'])->assertOk();

        $this->setPrerequisites('THC4', ['THC2', 'GEC5'], 'or')
            ->assertOk()
            ->assertJsonPath('message', 'THC4 now requires GEC5 or THC2.');

        $entry = $this->curricula['THC4']->fresh();
        $this->assertSame('OR', $entry->prerequisite_logic);
        $this->assertSame(['GEC5', 'THC2'], $this->prerequisiteCodes('THC4'));
        $this->assertDatabaseHas('system_logs', ['action' => 'Curriculum: BSTM THC4 prerequisites set to GEC5 OR THC2']);
    }

    public function test_an_empty_list_clears_them_and_logic_defaults_to_and(): void
    {
        $this->setPrerequisites('THC4', ['THC1', 'GEC5'], 'OR')->assertOk();

        $this->setPrerequisites('THC4', [], null)
            ->assertOk()
            ->assertJsonPath('message', 'THC4 has no prerequisites now.');

        $this->assertSame([], $this->prerequisiteCodes('THC4'));
        $this->assertSame('AND', $this->curricula['THC4']->fresh()->prerequisite_logic);
        $this->assertDatabaseHas('system_logs', ['action' => 'Curriculum: BSTM THC4 prerequisites cleared']);
    }

    public function test_saving_clears_unresolved_prerequisites(): void
    {
        $this->curricula['THC4']->update(['unresolved_prerequisites' => ['TPC 3']]);

        $this->setPrerequisites('THC4', ['THC2'])->assertOk();

        $this->assertNull($this->curricula['THC4']->fresh()->unresolved_prerequisites);
    }

    // ----------------------------------------------------------- refusals

    public function test_invalid_prerequisites_are_refused_and_nothing_changes(): void
    {
        $this->setPrerequisites('THC4', ['THC1'])->assertOk();
        $outside = Subject::create(['code' => 'HPC1', 'title' => 'Other Program Subject', 'units' => 3]);
        $logs = DB::table('system_logs')->count();

        $cases = [
            'same term'   => [['THC5'], ['subject_ids.0' => 'THC5 (Year 2 1st semester) must come before THC4 (Year 2 1st semester).']],
            'later term'  => [['GEC5', 'TPC9'], ['subject_ids.1' => 'TPC9 (Year 3 1st semester) must come before THC4 (Year 2 1st semester).']],
            'itself'      => [['THC4'], ['subject_ids.0' => "THC4 can't be its own prerequisite."]],
        ];
        foreach ($cases as $name => [$codes, $errors]) {
            $response = $this->setPrerequisites('THC4', $codes)->assertStatus(422);
            foreach ($errors as $key => $message) {
                $this->assertSame($message, $response->json('errors')[$key][0] ?? null, $name);
            }
        }

        $url = "/api/staff/curriculum/{$this->curricula['THC4']->id}/prerequisites";
        $this->putJson($url, ['subject_ids' => [$outside->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', "HPC1 is not in BSTM's curriculum.");
        $this->putJson($url, ['subject_ids' => [99999]])->assertStatus(422)->assertJsonValidationErrors('subject_ids.0');
        $this->putJson($url, ['subject_ids' => [$this->subjects['THC1']->id, $this->subjects['THC1']->id]])->assertStatus(422);
        $this->putJson($url, ['subject_ids' => [], 'logic' => 'XOR'])->assertStatus(422)->assertJsonValidationErrors(['logic' => 'The logic must be AND or OR.']);
        $this->putJson($url, ['logic' => 'AND'])->assertStatus(422)->assertJsonValidationErrors('subject_ids');
        $this->putJson('/api/staff/curriculum/9999/prerequisites', ['subject_ids' => []])->assertNotFound();

        $this->assertSame(['THC1'], $this->prerequisiteCodes('THC4'));
        $this->assertSame($logs, DB::table('system_logs')->count());
    }

    public function test_a_cycle_is_refused_even_when_the_terms_allow_it(): void
    {
        // THC2 (Y1S2) requires THC1 (Y1S1); then THC1 is moved to Y2S1 (#24
        // moves don't re-check prerequisite order), so THC1 now sits after THC2.
        $this->setPrerequisites('THC2', ['THC1'])->assertOk();
        $this->setPrerequisites('THC4', ['THC2'])->assertOk(); // THC4 (Y2S1) requires THC2
        $this->patchJson("/api/staff/curriculum/{$this->curricula['THC1']->id}", ['year_level' => 2, 'semester' => 2])->assertOk();

        // THC1 (now Y2S2) requiring THC4 (Y2S1) is "earlier", but THC4 needs THC2 needs THC1.
        $this->setPrerequisites('THC1', ['THC4'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['subject_ids.0' => ['THC4 already depends on THC1 (THC4 → THC2 → THC1), so this would make a loop.']]);

        // A direct loop: THC2 is earlier than THC1 now and already requires it.
        $this->setPrerequisites('THC1', ['THC2'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['subject_ids.0' => ['THC2 already depends on THC1 (THC2 → THC1), so this would make a loop.']]);

        $this->assertSame([], $this->prerequisiteCodes('THC1'));
    }

    public function test_changes_are_allowed_when_students_have_records_and_leave_them_alone(): void
    {
        $student = $this->makeStudent($this->program);
        $this->recordGrade($student, 'THC4', '2026-2027', 1, 2.0, 'Passed');
        $grades = DB::table('grades')->get()->toJson();

        $this->getJson("/api/staff/curriculum/{$this->curricula['THC4']->id}/impact")->assertOk()->assertJsonPath('students.count', 1);
        $this->setPrerequisites('THC4', ['THC1'])->assertOk();

        $this->assertSame($grades, DB::table('grades')->get()->toJson());
    }

    public function test_only_the_registrar_can_set_prerequisites(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->setPrerequisites('THC4', ['THC1'])->assertForbidden();

        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $this->setPrerequisites('THC4', ['THC1'])->assertForbidden();

        $this->assertSame([], $this->prerequisiteCodes('THC4'));
    }

    // -------------------------------------------------------- enforcement

    /**
     * Y1S1: A, B. Y1S2: C (builder AND A, B), D (builder OR A, B), and their
     * seeded twins SC (AND) and SD (OR), linked directly as the seeder does.
     * The student passes A and fails B.
     */
    private function enforcementFixture(): Student
    {
        $program = $this->makeProgram('BSIT', 'BS Information Technology');
        $this->makeCurriculum($program, [
            'A' => [1, 1], 'B' => [1, 1],
            'C' => [1, 2], 'D' => [1, 2], 'SC' => [1, 2], 'SD' => [1, 2],
        ], ['SC' => ['A', 'B'], 'SD' => ['A', 'B']]);
        $this->curricula['SD']->update(['prerequisite_logic' => 'OR']);

        $this->setPrerequisites('C', ['A', 'B'], 'AND')->assertOk();
        $this->setPrerequisites('D', ['A', 'B'], 'OR')->assertOk();

        $student = $this->makeStudent($program);
        $this->enrollNextTerm($student, ['A', 'B'])->assertCreated();
        $this->submitGrades($student, ['A' => 1.75, 'B' => 5.0])->assertOk();

        return $student;
    }

    public function test_builder_prerequisites_block_add_next_term_like_seeded_ones(): void
    {
        $student = $this->enforcementFixture();

        // The progression screen shows both pairs the same way.
        $progress = $this->academicProgress($student);
        $fields = ['prerequisite_status', 'prerequisite_logic', 'missing_prerequisites'];
        $row = fn (string $code) => array_intersect_key($this->availableSubject($progress, $code), array_flip($fields));
        $this->assertSame($row('SC'), $row('C'));
        $this->assertSame($row('SD'), $row('D'));
        $this->assertNotSame($row('C'), $row('D'));

        // AND with one prerequisite missing: refused, with the seeded wording.
        $this->enrollNextTerm($student, ['C'])
            ->assertStatus(422)
            ->assertJsonFragment(['B must be completed (Passed/Credited) before enrolling in C.']);
        $this->enrollNextTerm($student, ['SC'])
            ->assertStatus(422)
            ->assertJsonFragment(['B must be completed (Passed/Credited) before enrolling in SC.']);

        // OR with one prerequisite passed: allowed.
        $this->enrollNextTerm($student, ['D', 'SD'])->assertCreated()->assertJsonPath('enrolled_count', 2);
    }

    public function test_builder_prerequisites_block_manual_enrollment_like_seeded_ones(): void
    {
        $student = $this->enforcementFixture();
        $manual = fn (array $codes) => $this->postJson("/api/staff/students/{$student->student_id}/enrollments", [
            'academic_year' => '2026-2027', 'semester' => '2nd', 'year_level' => 1, 'subject_ids' => $this->idsFor($codes),
        ]);

        $builder = $manual(['C'])->assertStatus(422);
        $seeded = $manual(['SC'])->assertStatus(422);
        $this->assertSame(['B must be completed (Passed/Credited) before enrolling in C.'], $builder->json('errors.subject_ids'));
        $this->assertSame(['B must be completed (Passed/Credited) before enrolling in SC.'], $seeded->json('errors.subject_ids'));

        $manual(['D'])->assertCreated();
        $manual(['SD'])->assertCreated();
        $this->assertSame(0, Curriculum::query()->whereNotNull('unresolved_prerequisites')->count());
    }
}
