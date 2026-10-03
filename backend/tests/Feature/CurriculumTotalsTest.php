<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
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
 * #26: GET /staff/programs/{id}/curriculum returns live unit totals per
 * term, per year and for the program, computed from subjects.units on every
 * request. The `curriculum` array is unchanged for the pages that use it.
 */
class CurriculumTotalsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    /** Per-term units (Y1S1 .. Y4S2) of the seeded programs, as the #71 page shows them. */
    private const SEEDED = [
        'BSTM' => ['total' => 144, 'terms' => [20, 20, 20, 20, 18, 21, 19, 6], 'subjects' => [7, 7, 7, 7, 6, 7, 6, 1]],
        'BSHM' => ['total' => 143, 'terms' => [20, 20, 20, 20, 21, 21, 15, 6], 'subjects' => [7, 7, 7, 7, 7, 7, 5, 1]],
        'BSE'  => ['total' => 144, 'terms' => [20, 20, 20, 20, 17, 17, 19, 11], 'subjects' => [7, 7, 7, 7, 6, 6, 5, 3]],
    ];

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        Sanctum::actingAs($this->staff, ['*']);
    }

    private function curriculum(Program $program): array
    {
        return $this->getJson("/api/staff/programs/{$program->id}/curriculum")->assertOk()->json();
    }

    /** The per-term sums the #71 page computes from the `curriculum` rows. */
    private function pageTotals(array $rows): array
    {
        $terms = [];
        foreach ($rows as $row) {
            $key = $row['year_level'] . '-' . (int) $row['semester'];
            $terms[$key] = ($terms[$key] ?? 0) + (int) $row['subject']['units'];
        }
        ksort($terms);

        return $terms;
    }

    public function test_seeded_programs_have_the_expected_totals(): void
    {
        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class, BshmCurriculumSeeder::class, BseCurriculumSeeder::class]);

        foreach (self::SEEDED as $code => $expected) {
            $body = $this->curriculum(Program::where('code', $code)->firstOrFail());
            $totals = $body['totals'];

            $this->assertSame(['units' => $expected['total'], 'subjects' => 48], $totals['program'], $code);
            $this->assertSame(26, $totals['maximum_units']);

            $terms = [];
            foreach ([1, 2, 3, 4] as $year) {
                foreach ([1, 2] as $semester) {
                    $terms[] = ['year_level' => $year, 'semester' => $semester];
                }
            }
            foreach ($terms as $i => &$term) {
                $term += ['units' => $expected['terms'][$i], 'subjects' => $expected['subjects'][$i], 'over_max' => false];
            }
            unset($term);
            $this->assertSame($terms, $totals['terms'], $code);

            $years = [];
            foreach ([1, 2, 3, 4] as $year) {
                $years[] = [
                    'year_level' => $year,
                    'units'      => $expected['terms'][$year * 2 - 2] + $expected['terms'][$year * 2 - 1],
                    'subjects'   => $expected['subjects'][$year * 2 - 2] + $expected['subjects'][$year * 2 - 1],
                ];
            }
            $this->assertSame($years, $totals['years'], $code);

            // The same numbers the curriculum page adds up from the rows.
            $this->assertSame(array_values($this->pageTotals($body['curriculum'])), array_column($totals['terms'], 'units'), $code);
        }
    }

    public function test_the_curriculum_array_is_unchanged(): void
    {
        $program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($program, ['GEC4' => [1, 1], 'TPC1' => [1, 2], 'TPC2' => [2, 1, 5]], ['TPC2' => ['TPC1']]);

        $body = $this->curriculum($program);

        $expected = Curriculum::with(['subject', 'prerequisites:id,code,title'])
            ->where('program_id', $program->id)->orderBy('year_level')->orderBy('semester')->get();
        $expected->each(fn ($row) => $row->subject?->append('archived'));

        $this->assertSame(['curriculum', 'totals'], array_keys($body));
        $this->assertSame(json_decode($expected->toJson(), true), $body['curriculum']);

        // The year/semester filters still filter the rows; totals stay whole-program.
        $filtered = $this->getJson("/api/staff/programs/{$program->id}/curriculum?year_level=1&semester=2")->assertOk();
        $this->assertSame(['TPC1'], array_column(array_column($filtered->json('curriculum'), 'subject'), 'code'));
        $this->assertSame(['units' => 11, 'subjects' => 3], $filtered->json('totals.program'));
    }

    public function test_a_shared_subject_counts_in_each_program(): void
    {
        $bstm = $this->makeProgram('BSTM', 'BS Tourism Management');
        $bse = $this->makeProgram('BSE', 'BS Entrepreneurship');
        $this->makeCurriculum($bstm, ['GEC4' => [1, 1], 'TPC1' => [1, 1, 4]]);
        Curriculum::create(['program_id' => $bse->id, 'subject_id' => $this->subjects['GEC4']->id, 'year_level' => 1, 'semester' => '2']);

        $this->assertSame(['units' => 7, 'subjects' => 2], $this->curriculum($bstm)['totals']['program']);
        $this->assertSame(
            [['year_level' => 1, 'semester' => 2, 'units' => 3, 'subjects' => 1, 'over_max' => false]],
            $this->curriculum($bse)['totals']['terms']
        );
    }

    public function test_a_term_above_the_maximum_is_flagged(): void
    {
        $program = $this->makeProgram();
        // 9 x 3 = 27 units in Y1S1; 26 in Y1S2 is exactly the maximum.
        $defs = [];
        foreach (range(1, 9) as $i) {
            $defs["A{$i}"] = [1, 1];
        }
        foreach (range(1, 8) as $i) {
            $defs["B{$i}"] = [1, 2];
        }
        $defs['B9'] = [1, 2, 2];
        $this->makeCurriculum($program, $defs);

        $terms = $this->curriculum($program)['totals']['terms'];

        $this->assertSame(['year_level' => 1, 'semester' => 1, 'units' => 27, 'subjects' => 9, 'over_max' => true], $terms[0]);
        $this->assertSame(['year_level' => 1, 'semester' => 2, 'units' => 26, 'subjects' => 9, 'over_max' => false], $terms[1]);
    }

    public function test_totals_follow_every_place_move_and_remove(): void
    {
        $program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($program, ['GEC4' => [1, 1], 'TPC1' => [1, 2]]);
        $units = fn () => array_column($this->curriculum($program)['totals']['terms'], 'units', null);
        $this->assertSame([3, 3], $units());

        $new = Subject::create(['code' => 'TPC11', 'title' => 'Tour Guiding', 'units' => 5]);
        $entryId = $this->postJson("/api/staff/programs/{$program->id}/curriculum", ['subject_id' => $new->id, 'year_level' => 1, 'semester' => 1])
            ->assertCreated()->json('entry.id');
        $this->assertSame([8, 3], $units());

        $this->patchJson("/api/staff/curriculum/{$entryId}", ['year_level' => 2, 'semester' => 1])->assertOk();
        $this->assertSame([3, 3, 5], $units());
        $this->assertSame([6, 5], array_column($this->curriculum($program)['totals']['years'], 'units'));

        $this->deleteJson("/api/staff/curriculum/{$entryId}")->assertOk();
        $this->assertSame([3, 3], $units());
        $this->assertSame(['units' => 6, 'subjects' => 2], $this->curriculum($program)['totals']['program']);

        // A change to subjects.units shows up too; nothing is stored.
        $this->subjects['GEC4']->update(['units' => 2]);
        $this->assertSame([2, 3], $units());
    }

    public function test_admin_reads_totals(): void
    {
        $program = $this->makeProgram();
        $this->makeCurriculum($program, ['A' => [1, 1]]);
        Sanctum::actingAs($this->makeUser('admin'), ['*']);

        $this->getJson("/api/staff/programs/{$program->id}/curriculum")->assertOk()->assertJsonPath('totals.program.units', 3);
    }
}
