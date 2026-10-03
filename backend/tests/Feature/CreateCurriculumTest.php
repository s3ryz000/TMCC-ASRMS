<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #70: POST /staff/curriculums creates a program with its whole curriculum
 * in one transaction. Reused subjects link the same row; new subjects follow
 * the catalogue rules; every entry follows the #24 placement rules; any
 * error saves nothing and is keyed by entry index.
 */
class CreateCurriculumTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->makeCurriculum($this->makeProgram('BSTM', 'BS Tourism Management'), [
            'GEC4' => [1, 1], 'NSTP1' => [1, 1], 'TPC1' => [1, 2],
        ]);
        $this->subjects['GEC4']->update(['title' => 'Purposive Communication']);
        $this->subjects['OLD1'] = Subject::create(['code' => 'OLD1', 'title' => 'Retired Course', 'units' => 3]);
        $this->subjects['OLD1']->forceFill(['archived_at' => now()])->save();

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function payload(array $entries = null, array $program = []): array
    {
        return [
            'program' => array_merge(['code' => 'BSIT', 'name' => 'BS Information Technology', 'description' => null], $program),
            'entries' => $entries ?? [
                ['subject_id' => $this->subjects['GEC4']->id, 'year_level' => 1, 'semester' => '1st'],
                ['subject_id' => $this->subjects['NSTP1']->id, 'year_level' => 1, 'semester' => 1],
                ['new_subject' => ['code' => 'it 101', 'title' => 'Introduction to  Computing', 'units' => 3], 'year_level' => 1, 'semester' => '1'],
                ['new_subject' => ['code' => 'IT-102', 'title' => 'Computer Programming 1', 'units' => 4, 'description' => 'Lab'], 'year_level' => 1, 'semester' => '2nd'],
            ],
        ];
    }

    private function counts(): array
    {
        return [
            'programs'   => Program::count(),
            'subjects'   => Subject::count(),
            'curriculum' => Curriculum::count(),
            'prereqs'    => DB::table('curriculum_prerequisites')->count(),
            'logs'       => DB::table('system_logs')->count(),
        ];
    }

    public function test_registrar_creates_a_program_with_its_curriculum(): void
    {
        $subjectsBefore = Subject::count();

        $response = $this->postJson('/api/staff/curriculums', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'BSIT created with 4 subjects.')
            ->assertJsonPath('program.code', 'BSIT')
            ->assertJsonPath('new_subjects.0.code', 'IT101')
            ->assertJsonPath('new_subjects.1.code', 'IT102')
            ->assertJsonPath('totals.program', ['units' => 13, 'subjects' => 4])
            ->assertJsonPath('totals.terms.1', ['year_level' => 1, 'semester' => 2, 'units' => 4, 'subjects' => 1, 'over_max' => false]);

        $program = Program::where('code', 'BSIT')->firstOrFail();
        $this->assertSame($program->id, $response->json('program.id'));

        // Reused subjects link the same rows; two new ones in the registrar format.
        $this->assertSame($subjectsBefore + 2, Subject::count());
        $this->assertDatabaseHas('subjects', ['code' => 'IT101', 'title' => 'Introduction to Computing', 'units' => 3]);
        $this->assertDatabaseHas('subjects', ['code' => 'IT102', 'title' => 'Computer Programming 1', 'units' => 4, 'description' => 'Lab']);
        $this->assertSame(1, Subject::where('code', 'GEC4')->count());

        $rows = Curriculum::with('subject')->where('program_id', $program->id)->get()
            ->map(fn ($c) => "{$c->subject->code} Y{$c->year_level} S{$c->semester}")->sort()->values()->all();
        $this->assertSame(['GEC4 Y1 S1', 'IT101 Y1 S1', 'IT102 Y1 S2', 'NSTP1 Y1 S1'], $rows);
        $this->assertSame(['1', '2'], Curriculum::where('program_id', $program->id)->distinct()->orderBy('semester')->pluck('semester')->all());

        $this->assertDatabaseHas('system_logs', ['action' => 'Curriculum created: BSIT with 4 subjects', 'user_id' => $this->staff->id, 'role' => 'staff']);
        $this->assertDatabaseHas('system_logs', ['action' => 'Subject created: IT101 — Introduction to Computing']);

        // The other program is untouched.
        $this->assertSame(3, Curriculum::where('program_id', '!=', $program->id)->count());
    }

    public function test_any_invalid_entry_saves_nothing(): void
    {
        $gec4 = $this->subjects['GEC4']->id;
        $ok = ['subject_id' => $this->subjects['NSTP1']->id, 'year_level' => 1, 'semester' => 1];
        $new = fn (string $code, string $title) => ['new_subject' => ['code' => $code, 'title' => $title, 'units' => 3], 'year_level' => 1, 'semester' => 2];

        $cases = [
            'duplicate subject' => [
                [['subject_id' => $gec4, 'year_level' => 1, 'semester' => 1], $ok, ['subject_id' => $gec4, 'year_level' => 2, 'semester' => 1]],
                ['entries.2.subject_id' => 'GEC4 is already in BSIT, Year 1 1st semester.'],
            ],
            'bad semester' => [
                [$ok, ['subject_id' => $gec4, 'year_level' => 1, 'semester' => 'summer']],
                ['entries.1.semester' => 'The semester must be 1st or 2nd.'],
            ],
            'year 5' => [
                [['subject_id' => $gec4, 'year_level' => 5, 'semester' => 1], $ok],
                ['entries.0.year_level' => 'The year level field must be between 1 and 4.'],
            ],
            'duplicate new code in the payload' => [
                [$ok, $new('IT101', 'Introduction to Computing'), $new('it 101', 'Something Else')],
                ['entries.2.new_subject.code' => 'Another new subject in this curriculum already uses this code.'],
            ],
            'new code already in the catalogue' => [
                [$ok, $new('gec 4', 'Brand New Title')],
                ['entries.1.new_subject.code' => 'A subject with this code already exists.'],
            ],
            'near-duplicate title' => [
                [$ok, $new('IT101', 'purposive  communication')],
                ['entries.1.new_subject.title' => 'This looks like GEC4 Purposive Communication, which already exists. Reuse it instead.'],
            ],
            'duplicate new title in the payload' => [
                [$new('IT101', 'Web Systems'), $new('IT102', 'web  systems')],
                ['entries.1.new_subject.title' => 'Another new subject in this curriculum already has this title.'],
            ],
            'archived subject' => [
                [$ok, ['subject_id' => $this->subjects['OLD1']->id, 'year_level' => 1, 'semester' => 1]],
                ['entries.1.subject_id' => "OLD1 Retired Course is archived and can't be placed in a curriculum."],
            ],
            'both a subject and a new subject' => [
                [['subject_id' => $gec4, 'new_subject' => ['code' => 'IT101', 'title' => 'X', 'units' => 3], 'year_level' => 1, 'semester' => 1]],
                ['entries.0.subject_id' => 'Choose either an existing subject or a new one, not both.'],
            ],
            'neither' => [
                [['year_level' => 1, 'semester' => 1]],
                ['entries.0.subject_id' => 'Choose an existing subject or describe a new one.'],
            ],
            'new subject without a title' => [
                [['new_subject' => ['code' => 'IT101', 'units' => 3], 'year_level' => 1, 'semester' => 1]],
                ['entries.0.new_subject.title' => 'The descriptive title field is required.'],
            ],
            'unknown subject' => [
                [['subject_id' => 99999, 'year_level' => 1, 'semester' => 1]],
                ['entries.0.subject_id' => 'This subject does not exist.'],
            ],
            'no entries' => [
                [],
                ['entries' => 'Add at least one subject to the curriculum.'],
            ],
        ];

        $before = $this->counts();

        foreach ($cases as $name => [$entries, $errors]) {
            $response = $this->postJson('/api/staff/curriculums', $this->payload($entries));
            $response->assertStatus(422);
            foreach ($errors as $key => $message) {
                // Error keys contain dots, so read them from the array, not by path.
                $this->assertSame($message, $response->json('errors')[$key][0] ?? null, "{$name}: {$key} in " . json_encode($response->json('errors')));
            }
            $this->assertSame($before, $this->counts(), "{$name} saved something");
        }

        // A taken program code is refused the same way.
        $this->postJson('/api/staff/curriculums', $this->payload(null, ['code' => 'BSTM']))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['program.code' => ['A program with this code already exists.']]);
        $this->assertSame($before, $this->counts());
    }

    public function test_prerequisites_are_saved_by_entry_index(): void
    {
        $payload = $this->payload();
        // IT102 (Y1S2) requires IT101 (new, index 2) OR GEC4 (index 0).
        $payload['entries'][3]['prerequisites'] = [2, 0];
        $payload['entries'][3]['prerequisite_logic'] = 'or';

        $this->postJson('/api/staff/curriculums', $payload)->assertCreated();

        $program = Program::where('code', 'BSIT')->firstOrFail();
        $it102 = Curriculum::where('program_id', $program->id)->where('subject_id', Subject::where('code', 'IT102')->value('id'))->firstOrFail();
        $this->assertSame('OR', $it102->prerequisite_logic);
        $this->assertSame(['GEC4', 'IT101'], $it102->prerequisites->pluck('code')->sort()->values()->all());
        $this->assertSame(1, DB::table('curriculum_prerequisites')->whereIn('curriculum_id', Curriculum::where('program_id', $program->id)->select('id'))->distinct()->count('curriculum_id'));
        $this->assertSame('AND', Curriculum::where('program_id', $program->id)->where('subject_id', $this->subjects['GEC4']->id)->value('prerequisite_logic'));
    }

    public function test_invalid_prerequisites_in_the_payload_save_nothing(): void
    {
        $before = $this->counts();
        $cases = [
            'same term'  => [1, [0], 'entries.1.prerequisites.0', "GEC4 (Year 1 1st semester) must come before NSTP1 (Year 1 1st semester)."],
            'later term' => [0, [3], 'entries.0.prerequisites.0', "IT102 (Year 1 2nd semester) must come before GEC4 (Year 1 1st semester)."],
            'itself'     => [3, [3], 'entries.3.prerequisites.0', "IT102 can't be its own prerequisite."],
            'no entry'   => [3, [9], 'entries.3.prerequisites.0', 'This prerequisite is not in this curriculum.'],
        ];

        foreach ($cases as $name => [$entry, $prerequisites, $key, $message]) {
            $payload = $this->payload();
            $payload['entries'][$entry]['prerequisites'] = $prerequisites;
            $response = $this->postJson('/api/staff/curriculums', $payload)->assertStatus(422);
            $this->assertSame($message, $response->json('errors')[$key][0] ?? null, $name);
            $this->assertSame($before, $this->counts(), $name);
        }

        $payload = $this->payload();
        $payload['entries'][3]['prerequisite_logic'] = 'XOR';
        $this->postJson('/api/staff/curriculums', $payload)->assertStatus(422);
        $this->assertSame($before, $this->counts());
    }

    public function test_a_failure_while_writing_rolls_everything_back(): void
    {
        $before = $this->counts();
        $created = 0;
        Curriculum::creating(function () use (&$created) {
            if (++$created === 3) {
                throw new RuntimeException('simulated failure on the third entry');
            }
        });

        $this->postJson('/api/staff/curriculums', $this->payload())->assertStatus(500);

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseMissing('programs', ['code' => 'BSIT']);
        $this->assertDatabaseMissing('subjects', ['code' => 'IT101']);
    }

    public function test_admin_and_students_cannot_create(): void
    {
        $before = $this->counts();

        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->postJson('/api/staff/curriculums', $this->payload())->assertForbidden();

        Sanctum::actingAs($this->makeUser('student'), ['*']);
        $this->postJson('/api/staff/curriculums', $this->payload())->assertForbidden();

        $this->assertSame($before, $this->counts());
    }
}
