<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\SubjectCodePrefix;
use App\Models\User;
use Database\Seeders\BseCurriculumSeeder;
use Database\Seeders\BshmCurriculumSeeder;
use Database\Seeders\BstmCurriculumSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionClassConstant;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #16, registrar decision of 2 Oct 2026: subject codes are prefix + number
 * with no spaces or dashes (THC3, GEC4), and the GE subjects are renumbered.
 * Covers the rename migration, new and edited codes, and prefix matching.
 */
class SubjectCodeFormatTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Migration $migration;
    /** old code => [new code, title] */
    private array $map;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_03_000002_drop_separators_from_subject_codes.php');
        $this->map = (new ReflectionClassConstant($this->migration, 'MAP'))->getValue();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        Sanctum::actingAs($this->staff, ['*']);
    }

    /** The 93 subjects as they were before the migration; returns old code => id. */
    private function legacyCatalogue(): array
    {
        $ids = [];
        foreach ($this->map as $old => [, $title]) {
            $ids[$old] = Subject::create(['code' => $old, 'title' => $title, 'units' => 3])->id;
        }

        return $ids;
    }

    private function counts(): array
    {
        return collect(['subjects', 'curriculum', 'curriculum_prerequisites', 'enrollments', 'grades', 'students'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function codesById(): array
    {
        return Subject::orderBy('id')->pluck('code', 'id')->all();
    }

    // ------------------------------------------------------------ migration

    public function test_the_map_covers_93_subjects_in_the_new_format(): void
    {
        $this->assertCount(93, $this->map);
        $new = array_column($this->map, 0);
        $this->assertCount(93, array_unique($new));
        foreach ($new as $code) {
            $this->assertSame(Subject::formatCode($code), $code);
        }
        $this->assertSame(86, count(array_filter(array_keys($this->map), fn ($old) => $old !== $this->map[$old][0])));
    }

    public function test_the_migration_renames_every_code_and_keeps_every_record(): void
    {
        $ids = $this->legacyCatalogue();

        // A record on a renamed subject, including a soft-deleted enrollment and a prerequisite.
        $program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $student = $this->makeStudent($program);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'subject_id' => $ids['THC 3'], 'year_level' => 1, 'semester' => 2]);
        $curriculum->prerequisites()->attach($ids['GEC-PC']);
        $enrollment = Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $ids['THC 3'],
            'academic_year' => '2026-2027', 'semester' => '2', 'status' => 'completed', 'year_level' => 1,
        ]);
        Grade::create([
            'student_id' => $student->student_id, 'subject_id' => $ids['THC 3'], 'enrollment_id' => $enrollment->id,
            'academic_year' => '2026-2027', 'semester' => '2', 'grade_value' => 1.75, 'status' => 'Passed',
        ]);
        Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $ids['PATHFit 1'],
            'academic_year' => '2026-2027', 'semester' => '1', 'status' => 'Enrolled', 'year_level' => 1,
        ])->delete();
        SubjectCodePrefix::where('prefix', 'GE ELECT')->update(['active' => true]);

        $before = $this->counts();
        $logBefore = DB::table('subject_code_changes')->count();

        $this->migration->up();

        foreach ($this->map as $old => [$new, $title]) {
            $this->assertSame($new, Subject::find($ids[$old])->code, "{$old} should become {$new}");
            $this->assertSame($title, Subject::find($ids[$old])->title);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame(86, DB::table('subject_code_changes')->count() - $logBefore);
        $this->assertDatabaseHas('subject_code_changes', ['subject_id' => $ids['GEC-PC'], 'old_code' => 'GEC-PC', 'new_code' => 'GEC4']);
        $this->assertDatabaseMissing('subject_code_changes', ['old_code' => 'HRM']);
        $this->assertFalse(SubjectCodePrefix::where('prefix', 'GE ELECT')->value('active'));

        // Records still point at the same subjects, now under their new codes.
        $this->assertSame('THC3', $curriculum->fresh()->subject->code);
        $this->assertSame(['GEC4'], $curriculum->fresh()->prerequisites->pluck('code')->all());
        $this->assertSame('THC3', Grade::sole()->subject->code);
        $this->assertSame('PATHFIT1', Enrollment::onlyTrashed()->sole()->subject->code);

        $this->migration->down();

        foreach ($ids as $old => $id) {
            $this->assertSame($old, Subject::find($id)->code);
        }
        $this->assertSame($logBefore, DB::table('subject_code_changes')->count());
        $this->assertTrue(SubjectCodePrefix::where('prefix', 'GE ELECT')->value('active'));
    }

    public function test_a_subject_outside_the_map_gets_the_general_rule(): void
    {
        $ids = $this->legacyCatalogue();
        $extra = Subject::create(['code' => 'tpc 11', 'title' => 'Tourism Elective', 'units' => 3]);

        $this->migration->up();

        $this->assertSame('TPC11', $extra->fresh()->code);
        $this->assertSame('GEC4', Subject::find($ids['GEC-PC'])->code);
    }

    public function test_it_refuses_when_a_new_code_is_already_taken(): void
    {
        $this->legacyCatalogue();
        Subject::create(['code' => 'GEC4', 'title' => 'Something Else', 'units' => 3]);
        $codes = $this->codesById();
        $log = DB::table('subject_code_changes')->count();

        try {
            $this->migration->up();
            $this->fail('The rename should refuse a code that is already taken.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('GEC4', $e->getMessage());
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
        }

        $this->assertSame($codes, $this->codesById());
        $this->assertSame($log, DB::table('subject_code_changes')->count());
    }

    public function test_it_refuses_when_a_mapped_code_is_missing(): void
    {
        $ids = $this->legacyCatalogue();
        Subject::destroy($ids['HRM']);
        $codes = $this->codesById();

        try {
            $this->migration->up();
            $this->fail('The rename should refuse when a mapped code is missing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HRM (Human Resources Management) is missing', $e->getMessage());
        }

        $this->assertSame($codes, $this->codesById());
    }

    public function test_it_refuses_when_a_code_holds_a_different_course(): void
    {
        $ids = $this->legacyCatalogue();
        Subject::whereKey($ids['THC 3'])->update(['title' => 'Something Unexpected']);
        $codes = $this->codesById();

        try {
            $this->migration->up();
            $this->fail('The rename should refuse when a title does not match.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('THC 3 is "Something Unexpected"', $e->getMessage());
        }

        $this->assertSame($codes, $this->codesById());
    }

    public function test_on_an_empty_database_it_only_retires_ge_elect(): void
    {
        SubjectCodePrefix::where('prefix', 'GE ELECT')->update(['active' => true]);
        $log = DB::table('subject_code_changes')->count();

        $this->migration->up();

        $this->assertSame(0, Subject::count());
        $this->assertSame($log, DB::table('subject_code_changes')->count());
        $this->assertFalse(SubjectCodePrefix::where('prefix', 'GE ELECT')->value('active'));
    }

    // ------------------------------------------------------------ new codes

    public static function spellingsOfThc11(): array
    {
        return [
            'lowercase with space' => ['thc 11'],
            'dash'                 => ['THC-11'],
            'space'                => ['THC 11'],
            'padded'               => ['  Thc - 11 '],
        ];
    }

    /**
     * @dataProvider spellingsOfThc11
     */
    public function test_new_codes_are_stored_without_separators(string $spelling): void
    {
        $this->postJson('/api/staff/subjects', ['code' => $spelling, 'title' => 'Tourism Elective', 'units' => 3])
            ->assertCreated()
            ->assertJsonPath('subject.code', 'THC11');

        $this->assertDatabaseHas('subjects', ['code' => 'THC11']);
    }

    public function test_a_spacing_variant_of_an_existing_code_is_refused(): void
    {
        Subject::create(['code' => 'GEC4', 'title' => 'Purposive Communication', 'units' => 3]);

        foreach (['GEC 4', 'gec-4', 'GEC4'] as $spelling) {
            $this->postJson('/api/staff/subjects', ['code' => $spelling, 'title' => 'Another Course', 'units' => 3])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);
        }

        $this->assertSame(1, Subject::count());
    }

    public function test_editing_a_subject_reformats_a_changed_code(): void
    {
        $subject = Subject::create(['code' => 'TPC11', 'title' => 'Tourism Elective', 'units' => 3]);

        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'tpc 12', 'title' => 'Tourism Elective', 'units' => 3])
            ->assertOk()
            ->assertJsonPath('subject.code', 'TPC12');
    }

    // ------------------------------------------------------------ seeded catalogue

    public function test_a_fresh_seed_uses_the_new_codes_and_prefixes(): void
    {
        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class, BshmCurriculumSeeder::class, BseCurriculumSeeder::class]);

        $this->assertSame(93, Subject::count());
        $this->assertSame([], Subject::where('code', 'like', '% %')->orWhere('code', 'like', '%-%')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(array_column($this->map, 0), Subject::pluck('code')->all());
        $this->assertDatabaseHas('subjects', ['code' => 'GEE7', 'title' => 'PEACE Education']);
        $this->assertDatabaseHas('subjects', ['code' => 'GEE8', 'title' => 'Living in the IT Era']);

        $prefixes = collect($this->getJson('/api/staff/subject-prefixes')->assertOk()->json('prefixes'))->keyBy('prefix');
        $this->assertArrayNotHasKey('GE ELECT', $prefixes->all());
        $this->assertSame(9, $prefixes['GEC']['subjects_count']);
        $this->assertSame(8, $prefixes['GEE']['subjects_count']);
        $this->assertSame(93, $prefixes->sum('subjects_count'));
    }
}
