<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use Database\Seeders\BseCurriculumSeeder;
use Database\Seeders\BshmCurriculumSeeder;
use Database\Seeders\BstmCurriculumSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #20: every seeded prerequisite resolves to a real subject, and every
 * curriculum row can be reached: its prerequisites sit in an earlier term of
 * the same program (all of them for AND, at least one for OR).
 *
 * PRACTICUM's "Finished all Academic Requirements" is a program-completion
 * rule, not a subject, and is skipped by CurriculumSeederHelper on purpose
 * (follow-up #82); it never appears in unresolved_prerequisites.
 */
class SeededPrerequisitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ProgramSeeder::class, BstmCurriculumSeeder::class, BshmCurriculumSeeder::class, BseCurriculumSeeder::class]);
    }

    private function term(Curriculum $row): int
    {
        return (int) $row->year_level * 10 + (int) $row->semester;
    }

    public function test_no_seeded_curriculum_row_has_unresolved_prerequisites(): void
    {
        $unresolved = Curriculum::with(['program', 'subject'])->get()
            ->filter(fn (Curriculum $row) => ! empty($row->unresolved_prerequisites))
            ->map(fn (Curriculum $row) => "{$row->program->code} {$row->subject->code}: " . json_encode($row->unresolved_prerequisites))
            ->values()
            ->all();

        $this->assertSame([], $unresolved);
    }

    public function test_every_seeded_curriculum_row_can_be_reached(): void
    {
        $rows = Curriculum::with(['program', 'subject', 'prerequisites'])->get();
        $byProgramSubject = $rows->keyBy(fn (Curriculum $row) => "{$row->program_id}:{$row->subject_id}");

        $unreachable = [];
        $notEarlier = [];

        foreach ($rows as $row) {
            if ($row->prerequisites->isEmpty()) {
                continue;
            }

            $earlier = 0;
            foreach ($row->prerequisites as $prerequisite) {
                $entry = $byProgramSubject["{$row->program_id}:{$prerequisite->id}"] ?? null;
                $label = "{$row->program->code} {$row->subject->code} <- {$prerequisite->code}";

                if (! $entry) {
                    $unreachable[] = "{$label}: not in this program's curriculum";
                    continue;
                }
                if ($this->term($entry) < $this->term($row)) {
                    $earlier++;
                } else {
                    $notEarlier[] = "{$label} (" . ($this->term($entry) === $this->term($row) ? 'same term' : 'later term') . ')';
                }
            }

            $needed = strtoupper((string) $row->prerequisite_logic) === 'OR' ? 1 : $row->prerequisites->count();
            if ($earlier < $needed) {
                $unreachable[] = "{$row->program->code} {$row->subject->code}: only {$earlier} of {$needed} required prerequisite(s) come earlier";
            }
        }

        $this->assertSame([], $unreachable);

        // Links that can never be met in time. ENT6 needs "ENT4 or ENT5"; ENT5
        // is in the same term, so only ENT4 counts. Listed here so a new one
        // fails loudly instead of slipping in.
        $this->assertSame(['BSE ENT6 <- ENT5 (same term)'], $notEarlier);
    }
}
