<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\SubjectCodePrefix;
use App\Models\User;
use Database\Seeders\BseCurriculumSeeder;
use Database\Seeders\BshmCurriculumSeeder;
use Database\Seeders\BstmCurriculumSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #69: the CHED subject code prefixes, the rule that matches a subject code to
 * one of them, and the read-only list the catalogue's prefix filter uses.
 */
class SubjectCodePrefixTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        Sanctum::actingAs($this->staff, ['*']);
    }

    // --------------------------------------------------------------- the rule

    public function test_the_migration_fills_the_25_prefixes(): void
    {
        $this->assertSame(25, SubjectCodePrefix::count());
        $this->assertDatabaseHas('subject_code_prefixes', ['prefix' => 'TPC', 'full_name' => 'Tourism Professional Core', 'active' => true]);
        // Retired by #16 (its subjects are now GEE7 and GEE8), kept for rollback.
        $this->assertDatabaseHas('subject_code_prefixes', ['prefix' => 'GE ELECT', 'active' => false]);
    }

    public function test_codes_match_their_prefix(): void
    {
        $prefixes = SubjectCodePrefix::activePrefixes();

        $this->assertSame('GEC', SubjectCodePrefix::matchCode('GEC4', $prefixes));
        $this->assertSame('GEC', SubjectCodePrefix::matchCode('GEC9', $prefixes));
        $this->assertSame('GEE', SubjectCodePrefix::matchCode('GEE8', $prefixes));
        $this->assertSame('HRM', SubjectCodePrefix::matchCode('HRM', $prefixes));
        $this->assertSame('TPC', SubjectCodePrefix::matchCode('TPC10', $prefixes));
        $this->assertSame('PATHFIT', SubjectCodePrefix::matchCode('PATHFIT1', $prefixes));
        // Case and stray separators do not matter.
        $this->assertSame('PATHFIT', SubjectCodePrefix::matchCode('PATHFit 1', $prefixes));
        $this->assertSame('TPC', SubjectCodePrefix::matchCode('tpc 10', $prefixes));
    }

    public function test_a_code_must_start_with_the_prefix(): void
    {
        $prefixes = SubjectCodePrefix::activePrefixes();

        // "GEE" is its own prefix, not GEC; unknown letters match nothing.
        $this->assertSame('GEE', SubjectCodePrefix::matchCode('GEE5', $prefixes));
        $this->assertNull(SubjectCodePrefix::matchCode('IT101', $prefixes));
        $this->assertNull(SubjectCodePrefix::matchCode('XYZ1', $prefixes));
        $this->assertNull(SubjectCodePrefix::matchCode('1TPC', $prefixes));
    }

    public function test_the_longest_matching_prefix_wins(): void
    {
        $prefixes = SubjectCodePrefix::sortForMatching(['GE', 'GE ELECT']);

        $this->assertSame('GE ELECT', SubjectCodePrefix::matchCode('GE ELECT 4', $prefixes));
        $this->assertSame('GE', SubjectCodePrefix::matchCode('GE 5', $prefixes));
    }

    public function test_every_seeded_subject_maps_to_a_prefix(): void
    {
        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class, BshmCurriculumSeeder::class, BseCurriculumSeeder::class]);
        $prefixes = SubjectCodePrefix::activePrefixes();

        $unmatched = Subject::pluck('code')
            ->reject(fn (string $code) => SubjectCodePrefix::matchCode($code, $prefixes) !== null)
            ->values()
            ->all();

        $this->assertSame(93, Subject::count());
        $this->assertSame([], $unmatched, 'Subject codes with no prefix: '.implode(', ', $unmatched));

        // Exactly one: no other active prefix would also fit if tried on its own.
        $all = SubjectCodePrefix::where('active', true)->pluck('prefix')->all();
        foreach (Subject::pluck('code') as $code) {
            $fits = array_filter($all, fn ($p) => SubjectCodePrefix::matchCode($code, [$p]) !== null);
            $this->assertCount(1, $fits, "{$code} fits ".implode(', ', $fits));
        }
    }

    // ----------------------------------------------------------- the endpoint

    public function test_the_list_gives_labels_and_counts_ordered_by_prefix(): void
    {
        Subject::create(['code' => 'TPC1', 'title' => 'Tourism One', 'units' => 3]);
        Subject::create(['code' => 'TPC2', 'title' => 'Tourism Two', 'units' => 3]);
        $retired = Subject::create(['code' => 'TPC3', 'title' => 'Tourism Three', 'units' => 3]);
        $retired->forceFill(['archived_at' => now()])->save();

        $prefixes = collect($this->getJson('/api/staff/subject-prefixes')->assertOk()->json('prefixes'));

        $this->assertSame($prefixes->pluck('prefix')->sort()->values()->all(), $prefixes->pluck('prefix')->all());
        $this->assertSame([
            'prefix' => 'TPC', 'full_name' => 'Tourism Professional Core',
            'label' => 'TPC - Tourism Professional Core', 'subjects_count' => 2,
        ], $prefixes->firstWhere('prefix', 'TPC'));
        $this->assertSame(0, $prefixes->firstWhere('prefix', 'HUM')['subjects_count']);
    }

    public function test_inactive_prefixes_are_left_out(): void
    {
        SubjectCodePrefix::where('prefix', 'MOE')->update(['active' => false]);

        $prefixes = collect($this->getJson('/api/staff/subject-prefixes')->json('prefixes'))->pluck('prefix');

        $this->assertNotContains('MOE', $prefixes);
        // GE ELECT was retired by #16, so 23 of the 25 remain.
        $this->assertNotContains('GE ELECT', $prefixes);
        $this->assertCount(23, $prefixes);
    }

    public function test_subject_list_reports_each_subjects_prefix(): void
    {
        Subject::create(['code' => 'PATHFIT1', 'title' => 'Movement Competency Training', 'units' => 2]);
        Subject::create(['code' => 'IT101', 'title' => 'Intro to Computing', 'units' => 3]);

        $subjects = collect($this->getJson('/api/staff/subjects')->assertOk()->json('subjects'))->keyBy('code');

        $this->assertSame('PATHFIT', $subjects['PATHFIT1']['prefix']);
        $this->assertNull($subjects['IT101']['prefix']);
    }

    public function test_admin_can_read_the_prefixes(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);

        $this->getJson('/api/staff/subject-prefixes')->assertOk();
    }

    public function test_students_cannot_read_the_prefixes(): void
    {
        Sanctum::actingAs($this->makeUser('student', '2026-0001'), ['*']);

        $this->getJson('/api/staff/subject-prefixes')->assertForbidden();
    }

    public function test_guests_cannot_read_the_prefixes(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/staff/subject-prefixes')->assertUnauthorized();
    }
}
