<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #25: one subject row is shared by every program that places it. The
 * builder searches the catalogue, sees which programs already use a subject
 * and links that same row; nothing is copied.
 */
class SubjectReuseTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private Program $bstm;
    private Program $bshm;
    private Program $bse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->bstm = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->bshm = $this->makeProgram('BSHM', 'BS Hospitality Management');
        $this->bse = $this->makeProgram('BSE', 'BS Entrepreneurship');

        // GEC4 is a shared GE course; TPC1 belongs to BSTM only.
        $this->makeCurriculum($this->bstm, ['GEC4' => [1, 1], 'TPC1' => [1, 2], 'TPC2' => [2, 1]]);
        $this->subjects['GEC4']->update(['title' => 'Purposive Communication']);
        $this->subjects['TPC1']->update(['title' => 'Tourism Planning']);
        foreach ([$this->bshm, $this->bse] as $program) {
            Curriculum::create(['program_id' => $program->id, 'subject_id' => $this->subjects['GEC4']->id, 'year_level' => 1, 'semester' => '2']);
        }
        Subject::create(['code' => 'ENT1', 'title' => 'Entrepreneurial Behaviour', 'units' => 3]);
        Subject::create(['code' => 'OLD1', 'title' => 'Retired Tourism Course', 'units' => 3])
            ->forceFill(['archived_at' => now()])->save();

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function codes(string $query): array
    {
        return collect($this->getJson('/api/staff/subjects' . $query)->assertOk()->json('subjects'))->pluck('code')->all();
    }

    public function test_without_the_new_parameters_the_list_is_unchanged(): void
    {
        $response = $this->getJson('/api/staff/subjects')->assertOk();

        // Same top-level shape (no pagination envelope), every active subject,
        // same order and the same fields, plus `programs`.
        $this->assertSame(['subjects'], array_keys($response->json()));
        $this->assertSame(['ENT1', 'GEC4', 'TPC1', 'TPC2'], array_column($response->json('subjects'), 'code'));
        $this->assertSame([
            'id'               => $this->subjects['TPC1']->id,
            'code'             => 'TPC1',
            'title'            => 'Tourism Planning',
            'units'            => 3,
            'description'      => null,
            'curriculum_count' => 1,
            'grades_count'     => 0,
            'in_use'           => true,
            'archived'         => false,
            'prefix'           => 'TPC',
            'programs'         => ['BSTM'],
        ], $response->json('subjects.2'));

        $this->assertSame(['ENT1', 'GEC4', 'OLD1', 'TPC1', 'TPC2'], $this->codes('?include_archived=1'));
    }

    public function test_programs_lists_every_program_that_uses_a_shared_subject(): void
    {
        $subjects = collect($this->getJson('/api/staff/subjects')->json('subjects'))->keyBy('code');

        $this->assertSame(['BSE', 'BSHM', 'BSTM'], $subjects['GEC4']['programs']);
        $this->assertSame(3, $subjects['GEC4']['curriculum_count']);
        $this->assertSame(['BSTM'], $subjects['TPC2']['programs']);
        $this->assertSame([], $subjects['ENT1']['programs']);
    }

    public function test_search_by_code_ignores_case_and_separators(): void
    {
        $this->assertSame(['GEC4'], $this->codes('?search=gec'));
        $this->assertSame(['GEC4'], $this->codes('?search=' . urlencode('gec 4')));
        $this->assertSame(['TPC1', 'TPC2'], $this->codes('?search=tpc'));
        $this->assertSame(['TPC1'], $this->codes('?search=TPC-1'));
    }

    public function test_search_by_title_ignores_case(): void
    {
        $this->assertSame(['GEC4'], $this->codes('?search=PURPOSIVE'));
        $this->assertSame(['TPC1'], $this->codes('?search=' . urlencode('tourism plan')));
        // Archived subjects stay out of a search unless asked for.
        $this->assertSame([], $this->codes('?search=retired'));
        $this->assertSame(['OLD1'], $this->codes('?search=retired&include_archived=1'));
        // LIKE wildcards are matched literally.
        $this->assertSame([], $this->codes('?search=' . urlencode('%')));
        $this->assertSame([], $this->codes('?search=_'));
    }

    public function test_pagination(): void
    {
        $first = $this->getJson('/api/staff/subjects?per_page=3')->assertOk();
        $this->assertSame(['ENT1', 'GEC4', 'TPC1'], array_column($first->json('subjects'), 'code'));
        $this->assertSame(['current_page' => 1, 'per_page' => 3, 'total' => 4, 'last_page' => 2], $first->json('meta'));

        $second = $this->getJson('/api/staff/subjects?per_page=3&page=2')->assertOk();
        $this->assertSame(['TPC2'], array_column($second->json('subjects'), 'code'));
        $this->assertSame(['BSTM'], $second->json('subjects.0.programs'));

        $search = $this->getJson('/api/staff/subjects?search=tpc&per_page=1&page=2')->assertOk();
        $this->assertSame(['TPC2'], array_column($search->json('subjects'), 'code'));
        $this->assertSame(2, $search->json('meta.total'));

        $this->getJson('/api/staff/subjects?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/staff/subjects?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/staff/subjects?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_placing_a_subject_another_program_uses_links_the_same_row(): void
    {
        $subjectCount = Subject::count();
        $tpc1 = $this->subjects['TPC1'];

        $this->postJson("/api/staff/programs/{$this->bshm->id}/curriculum", [
            'subject_id' => $tpc1->id, 'year_level' => 2, 'semester' => 1,
        ])->assertCreated()->assertJsonPath('entry.subject_id', $tpc1->id);

        $this->assertSame($subjectCount, Subject::count());
        $this->assertSame(1, Subject::where('code', 'TPC1')->count());
        $this->assertSame(
            [$this->bstm->id, $this->bshm->id],
            Curriculum::where('subject_id', $tpc1->id)->orderBy('program_id')->pluck('program_id')->all()
        );
        $this->assertSame(['BSHM', 'BSTM'], collect($this->getJson('/api/staff/subjects?search=tpc1')->json('subjects'))->first()['programs']);
    }

    public function test_editing_a_shared_subject_returns_the_programs_it_reaches(): void
    {
        $gec4 = $this->subjects['GEC4'];

        $this->putJson("/api/staff/subjects/{$gec4->id}", [
            'code' => 'GEC4', 'title' => 'Purposive Communication', 'units' => 3, 'description' => 'Shared GE course',
        ])
            ->assertOk()
            ->assertJsonPath('subject.description', 'Shared GE course')
            ->assertJsonPath('programs', ['BSE', 'BSHM', 'BSTM']);

        $this->putJson("/api/staff/subjects/{$this->subjects['TPC2']->id}", [
            'code' => 'TPC2', 'title' => 'Subject TPC2', 'units' => 3,
        ])->assertOk()->assertJsonPath('programs', ['BSTM']);
    }

    public function test_creating_a_new_subject_still_follows_the_catalogue_rules(): void
    {
        $this->postJson('/api/staff/subjects', ['code' => 'gec 4', 'title' => 'Anything', 'units' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);
        $this->postJson('/api/staff/subjects', ['code' => 'GEC99', 'title' => 'purposive  communication', 'units' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->postJson('/api/staff/subjects', ['code' => 'tpc 3', 'title' => 'Tour Guiding', 'units' => 3])
            ->assertCreated()
            ->assertJsonPath('subject.code', 'TPC3');
    }

    public function test_admin_can_search_and_students_cannot(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->getJson('/api/staff/subjects?search=gec&per_page=5')->assertOk()->assertJsonPath('subjects.0.programs', ['BSE', 'BSHM', 'BSTM']);

        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $this->getJson('/api/staff/subjects?search=gec')->assertForbidden();
    }
}
