<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use Database\Seeders\BseCurriculumSeeder;
use Database\Seeders\BshmCurriculumSeeder;
use Database\Seeders\BstmCurriculumSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #16: a fresh seed gives every course one subject row with one unique code,
 * shared by all programs, without changing any program's curriculum.
 */
class SubjectCatalogSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class, BshmCurriculumSeeder::class, BseCurriculumSeeder::class]);
    }

    public function test_no_subject_code_appears_twice(): void
    {
        $this->assertSame(0, Subject::query()->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->get()->count());
        $this->assertSame(93, Subject::count());
    }

    public function test_ge_core_subjects_are_shared_by_all_three_programs(): void
    {
        foreach (['GEC1', 'GEC2', 'GEC3', 'GEC4', 'GEC5', 'GEC6', 'GEC7', 'GEC8', 'GEC9'] as $code) {
            $programs = Curriculum::whereHas('subject', fn ($q) => $q->where('code', $code))
                ->with('program')->get()->pluck('program.code')->sort()->values()->all();

            $this->assertSame(['BSE', 'BSHM', 'BSTM'], $programs, "{$code} should be in every program's curriculum");
        }
    }

    public function test_canonical_codes_and_titles_replace_the_program_local_ones(): void
    {
        $this->assertDatabaseHas('subjects', ['code' => 'GEE2', 'title' => 'The Entrepreneurial Mind']);
        $this->assertDatabaseHas('subjects', ['code' => 'GEE5', 'title' => 'Indigenous People']);
        $this->assertDatabaseHas('subjects', ['code' => 'TMPE1', 'title' => 'Recreation and Leisure Management']);
        $this->assertDatabaseHas('subjects', ['code' => 'HMPE1', 'title' => 'Introduction to Transport Services']);
        $this->assertDatabaseHas('subjects', ['code' => 'HMPE2', 'title' => 'Bar and Beverage Management with Laboratory']);

        foreach (['GE 1', 'GE 2', 'GE 5', 'GE 9', 'GE ELECT 1', 'GE ELECT 5', 'RIZAL', 'GE ELECT 3', 'GE ELECT 4', 'GEC-PC', 'GEE-IP', 'THC 3', 'PATHFit 1'] as $legacy) {
            $this->assertDatabaseMissing('subjects', ['code' => $legacy]);
        }
    }

    public function test_each_program_keeps_its_curriculum_and_prerequisites(): void
    {
        $expected = ['BSE' => [48, 44], 'BSHM' => [48, 35], 'BSTM' => [48, 38]];

        foreach ($expected as $code => [$rows, $prereqs]) {
            $program = Program::where('code', $code)->firstOrFail();

            $this->assertSame($rows, $program->curriculum()->count(), "{$code} curriculum rows");
            $this->assertSame($prereqs, DB::table('curriculum_prerequisites')
                ->join('curriculum', 'curriculum.id', '=', 'curriculum_prerequisites.curriculum_id')
                ->where('curriculum.program_id', $program->id)
                ->count(), "{$code} prerequisites");
        }
    }

    public function test_program_local_prerequisite_codes_resolve_to_the_right_course(): void
    {
        // BSHM's "GE 5" is Indigenous People and BSTM's "HMPE 1" is
        // Recreation and Leisure Management; both must survive the renaming.
        $prereqCodes = function (string $program, string $subjectCode) {
            return Curriculum::with('prerequisites')
                ->whereHas('program', fn ($q) => $q->where('code', $program))
                ->whereHas('prerequisites', fn ($q) => $q->where('code', $subjectCode))
                ->count();
        };

        $this->assertGreaterThan(0, $prereqCodes('BSHM', 'GEE5'));
        $this->assertGreaterThan(0, $prereqCodes('BSTM', 'TMPE1'));
        $this->assertSame(0, $prereqCodes('BSTM', 'GEE5'));
    }
}
