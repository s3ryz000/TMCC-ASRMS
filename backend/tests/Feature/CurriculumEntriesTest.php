<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #24: the registrar places, moves and removes subjects in a program's
 * curriculum. A subject appears once per program, terms are Year 1-4 and
 * 1st/2nd semester only, archived subjects and programs take no new
 * placements, and every change is logged with its actor.
 */
class CurriculumEntriesTest extends TestCase
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
            'GEC4' => [1, 1],
            'TPC1' => [1, 2],
            'TPC2' => [2, 1],
        ], ['TPC2' => ['TPC1']]);

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function freeSubject(string $code = 'TPC11', string $title = 'Tour Guiding'): Subject
    {
        return Subject::create(['code' => $code, 'title' => $title, 'units' => 3]);
    }

    private function place(array $payload, ?Program $program = null)
    {
        return $this->postJson('/api/staff/programs/' . ($program ?? $this->program)->id . '/curriculum', $payload);
    }

    // ------------------------------------------------------------ place

    public function test_registrar_places_a_subject(): void
    {
        $subject = $this->freeSubject();

        $response = $this->place(['subject_id' => $subject->id, 'year_level' => 3, 'semester' => '1st'])
            ->assertCreated()
            ->assertJsonPath('message', 'TPC11 placed in BSTM, Year 3 1st semester.')
            ->assertJsonPath('entry.subject.code', 'TPC11')
            ->assertJsonPath('entry.subject.archived', false)
            ->assertJsonPath('entry.year_level', 3)
            ->assertJsonPath('entry.semester', '1')
            ->assertJsonPath('entry.prerequisites', []);

        $this->assertDatabaseHas('curriculum', [
            'id' => $response->json('entry.id'), 'program_id' => $this->program->id,
            'subject_id' => $subject->id, 'year_level' => 3, 'semester' => '1',
        ]);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Curriculum: placed TPC11 in BSTM Y3 S1', 'user_id' => $this->staff->id, 'role' => 'staff',
        ]);
    }

    public function test_every_semester_spelling_is_stored_as_1_or_2(): void
    {
        foreach ([[1, '1'], ['2', '2'], ['2nd Semester', '2'], ['1st', '1']] as $i => [$input, $stored]) {
            $subject = $this->freeSubject("NEW{$i}", "New Subject {$i}");
            $this->place(['subject_id' => $subject->id, 'year_level' => 4, 'semester' => $input])->assertCreated();
            $this->assertSame($stored, Curriculum::where('subject_id', $subject->id)->value('semester'));
        }
    }

    public function test_a_subject_appears_once_per_program(): void
    {
        $this->place(['subject_id' => $this->subjects['GEC4']->id, 'year_level' => 2, 'semester' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id' => 'GEC4 is already in BSTM, Year 1 1st semester.']);

        $this->assertSame(1, Curriculum::where('subject_id', $this->subjects['GEC4']->id)->count());
    }

    public function test_the_database_also_refuses_a_second_placement(): void
    {
        $this->expectException(QueryException::class);

        Curriculum::create([
            'program_id' => $this->program->id, 'subject_id' => $this->subjects['GEC4']->id,
            'year_level' => 4, 'semester' => '2',
        ]);
    }

    public function test_bad_terms_are_refused_without_a_default(): void
    {
        $subject = $this->freeSubject();

        foreach (['summer', '3', 3, '', 'third', null, ['1']] as $semester) {
            $this->place(['subject_id' => $subject->id, 'year_level' => 1, 'semester' => $semester])
                ->assertStatus(422)
                ->assertJsonValidationErrors('semester');
        }
        foreach ([0, 5, 'two', null] as $year) {
            $this->place(['subject_id' => $subject->id, 'year_level' => $year, 'semester' => 1])
                ->assertStatus(422)
                ->assertJsonValidationErrors('year_level');
        }
        $this->place(['subject_id' => 99999, 'year_level' => 1, 'semester' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');

        $this->assertSame(0, Curriculum::where('subject_id', $subject->id)->count());
    }

    public function test_archived_subjects_and_programs_take_no_new_placements(): void
    {
        $subject = $this->freeSubject();
        $subject->forceFill(['archived_at' => now()])->save();

        $this->place(['subject_id' => $subject->id, 'year_level' => 1, 'semester' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id' => "TPC11 Tour Guiding is archived and can't be placed in a curriculum."]);

        $active = $this->freeSubject('TPC12', 'Events Management');
        $this->program->forceFill(['archived_at' => now()])->save();

        $this->place(['subject_id' => $active->id, 'year_level' => 1, 'semester' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['program' => "BSTM is archived and can't receive new subjects."]);

        $this->assertSame(0, Curriculum::whereIn('subject_id', [$subject->id, $active->id])->count());
    }

    public function test_unknown_program_or_entry_is_404(): void
    {
        $this->postJson('/api/staff/programs/9999/curriculum', ['subject_id' => $this->freeSubject()->id, 'year_level' => 1, 'semester' => 1])
            ->assertNotFound();
        $this->patchJson('/api/staff/curriculum/9999', ['year_level' => 1, 'semester' => 1])->assertNotFound();
        $this->deleteJson('/api/staff/curriculum/9999')->assertNotFound();
    }

    // ------------------------------------------------------------- move

    public function test_registrar_moves_a_subject(): void
    {
        $entry = $this->curricula['TPC2'];

        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 2, 'semester' => '2nd'])
            ->assertOk()
            ->assertJsonPath('message', 'TPC2 moved to Year 2 2nd semester.')
            ->assertJsonPath('entry.semester', '2')
            ->assertJsonPath('entry.prerequisites.0.code', 'TPC1');

        $this->assertDatabaseHas('curriculum', ['id' => $entry->id, 'year_level' => 2, 'semester' => '2']);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Curriculum: moved TPC2 in BSTM from Y2 S1 to Y2 S2', 'user_id' => $this->staff->id,
        ]);
        // Its prerequisite links move with it.
        $this->assertSame([$this->subjects['TPC1']->id], $entry->fresh()->prerequisites->pluck('id')->all());
    }

    public function test_a_move_to_the_same_term_changes_and_logs_nothing(): void
    {
        $entry = $this->curricula['GEC4'];
        $logs = DB::table('system_logs')->count();

        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 1, 'semester' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'GEC4 is already in Year 1 1st semester.');

        $this->assertSame($logs, DB::table('system_logs')->count());
    }

    public function test_a_move_needs_a_valid_term(): void
    {
        $entry = $this->curricula['GEC4'];

        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 5, 'semester' => 'summer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['year_level', 'semester']);
        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');

        $this->assertDatabaseHas('curriculum', ['id' => $entry->id, 'year_level' => 1, 'semester' => '1']);
    }

    // ----------------------------------------------------------- remove

    public function test_registrar_removes_a_subject_with_its_prerequisite_links(): void
    {
        $entry = $this->curricula['TPC2'];

        $this->deleteJson("/api/staff/curriculum/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('message', 'TPC2 removed from BSTM.');

        $this->assertDatabaseMissing('curriculum', ['id' => $entry->id]);
        $this->assertDatabaseMissing('curriculum_prerequisites', ['curriculum_id' => $entry->id]);
        // The subject itself stays in the catalogue.
        $this->assertDatabaseHas('subjects', ['id' => $this->subjects['TPC2']->id]);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Curriculum: removed TPC2 from BSTM Y2 S1', 'user_id' => $this->staff->id,
        ]);
    }

    public function test_a_subject_another_entry_requires_cannot_be_removed(): void
    {
        $entry = $this->curricula['TPC1'];

        $this->deleteJson("/api/staff/curriculum/{$entry->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', "TPC1 can't be removed from BSTM: TPC2 lists it as a prerequisite.")
            ->assertJsonPath('required_by.0.code', 'TPC2')
            ->assertJsonPath('required_by.0.entry_id', $this->curricula['TPC2']->id);

        $this->assertDatabaseHas('curriculum', ['id' => $entry->id]);
        $this->assertDatabaseHas('curriculum_prerequisites', ['curriculum_id' => $this->curricula['TPC2']->id]);
    }

    public function test_another_programs_prerequisite_does_not_block_removal(): void
    {
        $other = $this->makeProgram('BSHM', 'BS Hospitality Management');
        Curriculum::create(['program_id' => $other->id, 'subject_id' => $this->subjects['TPC2']->id, 'year_level' => 2, 'semester' => '1'])
            ->prerequisites()->attach($this->subjects['GEC4']->id);

        $this->deleteJson("/api/staff/curriculum/{$this->curricula['GEC4']->id}")->assertOk();
    }

    // ------------------------------------------------------------ roles

    public function test_admin_reads_but_cannot_write(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $entry = $this->curricula['GEC4'];

        $this->getJson("/api/staff/programs/{$this->program->id}/curriculum")->assertOk()->assertJsonCount(3, 'curriculum');
        $this->place(['subject_id' => $this->freeSubject()->id, 'year_level' => 1, 'semester' => 1])->assertForbidden();
        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 2, 'semester' => 1])->assertForbidden();
        $this->deleteJson("/api/staff/curriculum/{$entry->id}")->assertForbidden();

        $this->assertSame(3, Curriculum::count());
        $this->assertDatabaseHas('curriculum', ['id' => $entry->id, 'year_level' => 1]);
    }

    public function test_students_are_refused_everywhere(): void
    {
        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $entry = $this->curricula['GEC4'];

        $this->getJson("/api/staff/programs/{$this->program->id}/curriculum")->assertForbidden();
        $this->place(['subject_id' => $this->freeSubject()->id, 'year_level' => 1, 'semester' => 1])->assertForbidden();
        $this->patchJson("/api/staff/curriculum/{$entry->id}", ['year_level' => 2, 'semester' => 1])->assertForbidden();
        $this->deleteJson("/api/staff/curriculum/{$entry->id}")->assertForbidden();

        $this->assertSame(3, Curriculum::count());
    }

    // -------------------------------------------------------- migration

    public function test_the_unique_index_migration_refuses_existing_duplicates(): void
    {
        $migration = require database_path('migrations/2026_10_03_000005_add_unique_program_subject_to_curriculum.php');
        $migration->down();

        Curriculum::create(['program_id' => $this->program->id, 'subject_id' => $this->subjects['GEC4']->id, 'year_level' => 4, 'semester' => '2']);

        try {
            $migration->up();
            $this->fail('The migration should refuse a subject placed twice in one program.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("program {$this->program->id}, subject {$this->subjects['GEC4']->id} (2x)", $e->getMessage());
        }

        $this->assertFalse(collect(Schema::getIndexes('curriculum'))->contains('name', 'curriculum_program_id_subject_id_unique'));
        $this->assertSame(4, Curriculum::count());
    }
}
