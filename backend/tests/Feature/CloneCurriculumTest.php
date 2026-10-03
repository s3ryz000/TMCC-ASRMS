<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\BstmCurriculumSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #31: a program's curriculum is cloned into a new program, the template for
 * a revised curriculum (#28: no versioning). The copy has the same entries,
 * prerequisites and totals, shares the subject rows and is independent.
 */
class CloneCurriculumTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private Program $bstm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class]);
        $this->staff = $this->makeUser('staff');
        $this->bstm = Program::where('code', 'BSTM')->firstOrFail();

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function cloneBstm(array $payload = ['code' => 'BSTM-2027', 'name' => 'BS Tourism Management (2027 curriculum)'])
    {
        return $this->postJson("/api/staff/programs/{$this->bstm->id}/clone", $payload);
    }

    /** Every entry of a program as "CODE Y1 S2 AND [GEC4, THC1]", sorted. */
    private function snapshot(Program $program): array
    {
        return Curriculum::with(['subject', 'prerequisites'])->where('program_id', $program->id)->get()
            ->map(fn (Curriculum $c) => sprintf(
                '%s Y%d S%d %s [%s] %s',
                $c->subject->code, $c->year_level, $c->semester, $c->prerequisite_logic,
                $c->prerequisites->pluck('code')->sort()->join(', '),
                json_encode($c->unresolved_prerequisites),
            ))
            ->sort()->values()->all();
    }

    private function entry(Program $program, string $code): Curriculum
    {
        return Curriculum::where('program_id', $program->id)->where('subject_id', Subject::where('code', $code)->value('id'))->firstOrFail();
    }

    public function test_the_clone_has_the_same_entries_prerequisites_and_totals(): void
    {
        $subjects = Subject::count();
        $sourcePrerequisites = DB::table('curriculum_prerequisites')->count();

        $response = $this->cloneBstm()
            ->assertCreated()
            ->assertJsonPath('message', 'BSTM-2027 created from BSTM with 48 subjects.')
            ->assertJsonPath('entries', 48)
            ->assertJsonPath('prerequisites', 38)
            ->assertJsonPath('archived_subjects', [])
            ->assertJsonPath('totals.program', ['units' => 144, 'subjects' => 48]);

        $clone = Program::findOrFail($response->json('program.id'));
        $this->assertSame('BSTM-2027', $clone->code);
        $this->assertSame($this->snapshot($this->bstm), $this->snapshot($clone));
        $this->assertSame(
            $this->getJson("/api/staff/programs/{$this->bstm->id}/curriculum")->json('totals'),
            $this->getJson("/api/staff/programs/{$clone->id}/curriculum")->json('totals'),
        );

        // Same subject rows, new entries and links.
        $this->assertSame($subjects, Subject::count());
        $this->assertSame(48, Curriculum::where('program_id', $clone->id)->count());
        $this->assertSame($sourcePrerequisites * 2, DB::table('curriculum_prerequisites')->count());
        $this->assertSame(0, Curriculum::where('program_id', $clone->id)->whereIn('id', Curriculum::where('program_id', $this->bstm->id)->select('id'))->count());
        $this->assertDatabaseHas('system_logs', ['action' => 'Curriculum cloned: BSTM → BSTM-2027 (48 subjects)', 'user_id' => $this->staff->id]);
    }

    public function test_editing_the_clone_leaves_the_source_unchanged(): void
    {
        // A BSTM student has a grade in TPC1, so BSTM's TPC1 can't move (#28)...
        $student = $this->makeStudent($this->bstm);
        Grade::create([
            'student_id' => $student->student_id, 'subject_id' => Subject::where('code', 'TPC1')->value('id'),
            'academic_year' => '2026-2027', 'semester' => '1', 'grade_value' => 2.0, 'status' => 'Passed',
        ]);
        $this->patchJson("/api/staff/curriculum/{$this->entry($this->bstm, 'TPC1')->id}", ['year_level' => 2, 'semester' => 1])->assertStatus(409);

        $before = $this->snapshot($this->bstm);
        $sourceTotals = $this->getJson("/api/staff/programs/{$this->bstm->id}/curriculum")->json('totals');
        $clone = Program::findOrFail($this->cloneBstm()->assertCreated()->json('program.id'));

        // ...but the clone has no students yet: move, re-link and remove freely.
        $tpc1 = $this->entry($clone, 'TPC1');
        $this->patchJson("/api/staff/curriculum/{$tpc1->id}", ['year_level' => 2, 'semester' => 1])->assertOk();
        $withPrerequisites = Curriculum::where('program_id', $clone->id)->has('prerequisites')->with('prerequisites')->orderBy('id')->firstOrFail();
        $this->putJson("/api/staff/curriculum/{$withPrerequisites->id}/prerequisites", ['subject_ids' => [], 'logic' => 'AND'])->assertOk();
        $removable = Curriculum::where('program_id', $clone->id)
            ->whereNotIn('subject_id', DB::table('curriculum_prerequisites')->whereIn('curriculum_id', Curriculum::where('program_id', $clone->id)->select('id'))->select('prerequisite_subject_id'))
            ->orderByDesc('year_level')->firstOrFail();
        $this->deleteJson("/api/staff/curriculum/{$removable->id}")->assertOk();

        $this->assertNotSame($before, $this->snapshot($clone));
        $this->assertSame($before, $this->snapshot($this->bstm));
        $this->assertSame($sourceTotals, $this->getJson("/api/staff/programs/{$this->bstm->id}/curriculum")->json('totals'));
    }

    public function test_a_taken_code_saves_nothing(): void
    {
        $counts = fn () => [Program::count(), Curriculum::count(), DB::table('curriculum_prerequisites')->count(), DB::table('system_logs')->count()];
        $before = $counts();

        $this->cloneBstm(['code' => 'BSTM', 'name' => 'Copy'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A program with this code already exists.']);
        $this->cloneBstm(['code' => 'BSTM-2027'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame($before, $counts());
    }

    public function test_only_the_registrar_can_clone(): void
    {
        $before = Program::count();

        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->cloneBstm()->assertForbidden();
        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $this->cloneBstm()->assertForbidden();

        Sanctum::actingAs($this->staff, ['*']);
        $this->postJson('/api/staff/programs/9999/clone', ['code' => 'X', 'name' => 'Y'])->assertNotFound();

        $this->assertSame($before, Program::count());
    }
}
