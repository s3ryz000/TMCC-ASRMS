<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * CurriculumSeederHelper
 *
 * Reusable trait for BstmCurriculumSeeder, BshmCurriculumSeeder, BseCurriculumSeeder.
 *
 * Each $row in the $rows array must have:
 *   - code        (string)  Subject code
 *   - title       (string)  Subject title
 *   - units       (int)
 *   - year_level  (int)     1–4
 *   - semester    (int)     1 or 2
 *   - prerequisites (array) Array of subject codes that must be passed first. Empty [] means none.
 *   - description (string)  Optional
 *
 * Rows keep the codes and titles their curriculum document prints. Each one is
 * translated through SubjectCatalog to its canonical code and title, and the
 * subject is found or created by that code alone, so every program shares one
 * row per course (e.g. BSE's "GE 1" and BSTM's "GE 4" are both GEC8), stored
 * in the registrar's code format ("THC 3" becomes THC3).
 *
 * Prerequisites are resolved within the program's own curriculum context only —
 * never via a global subject-code pluck — using the program-local codes.
 *
 * The string "Finished all Academic Requirements" is skipped with a warning.
 */
trait CurriculumSeederHelper
{
    /**
     * Alias map: normalize alternate or abbreviated codes to their canonical form.
     * Add entries here only when a prerequisite reference uses a non-standard code.
     */
    private array $codeAliases = [
        'THC2' => 'THC 2',
        'HPC1' => 'HPC 1',
        'HPC5' => 'HPC 5',
        'HMPC5' => 'HPC 5',
        'PE 1' => 'PATHFit 1',
        'PE 2' => 'PATHFit 2',
        'PE 3' => 'PATHFit 3',
        'PE 4' => 'PATHFit 4',
    ];

    /**
     * Normalize a subject code: trim, collapse spaces.
     * Preserves original casing (PATHFit, GE ELECT, etc.).
     */
    private function normalizeCode(string $code): string
    {
        $trimmed = preg_replace('/\s+/', ' ', trim($code));
        return $this->codeAliases[$trimmed] ?? $trimmed;
    }

    /**
     * Main entry point. Call this from each curriculum seeder's run() method.
     *
     * @param string $programCode  e.g. 'BSTM'
     * @param array  $rows         Array of curriculum row definitions
     */
    public function seedProgramCurriculum(string $programCode, array $rows): void
    {
        $program = Program::where('code', $programCode)->first();

        if (!$program) {
            $this->command->error("[SEEDER] Program '{$programCode}' not found. Run ProgramSeeder first.");
            return;
        }

        // --- PASS 1: Create/find subjects and curriculum rows ---

        // Local map: normalizedCode => Curriculum model (for this program only)
        $curriculumMap = [];

        foreach ($rows as $row) {
            $rawCode = $row['code'];
            $title = $row['title'];
            $units = $row['units'];
            $yearLevel = $row['year_level'];
            $semester = $row['semester'];
            $desc = $row['description'] ?? null;

            $code = $this->normalizeCode($rawCode);

            $prereqLogic = $row['prerequisite_logic'] ?? 'AND';

            // One row per course, keyed on its canonical code (unique index).
            // title, units and description are only applied on creation, not overwritten.
            [$subjectCode, $subjectTitle] = SubjectCatalog::resolve($code, $title);
            $subject = Subject::firstOrCreate(
                ['code' => $subjectCode],
                [
                    'title' => $subjectTitle,
                    'units' => $units,
                    'description' => $desc,
                ]
            );

            // A different course under the same code would be silently merged;
            // it needs its own entry in SubjectCatalog instead.
            if ($subject->title !== $subjectTitle) {
                $this->command->warn(
                    "[WARN][{$programCode}] {$subjectCode} already exists as '{$subject->title}'; " .
                    "'{$title}' was attached to it. Add a SubjectCatalog entry if they are different courses."
                );
            }

            // Idempotent curriculum row
            $curriculum = Curriculum::updateOrCreate(
                [
                    'program_id' => $program->id,
                    'subject_id' => $subject->id,
                    'year_level' => $yearLevel,
                    'semester' => $semester,
                ],
                [
                    'prerequisite_logic' => $prereqLogic,
                ] // Add logic here
            );

            // Store in local map using the program-local normalized code, which is
            // what this program's prerequisite lists refer to.
            $curriculumMap[$code] = $curriculum;
        }

        // --- PASS 2: Create prerequisite relationships ---
        // Track unresolved prereqs per subject so we can persist them to the DB.
        // Key: normalized subject code  Value: array of unresolved prereq codes

        $unresolvedBySubject = []; // normalizedCode => [unresolved codes]

        foreach ($rows as $row) {
            $code = $this->normalizeCode($row['code']);
            $prerequisites = $row['prerequisites'] ?? [];

            if (empty($prerequisites)) {
                if ($curriculum = $curriculumMap[$code] ?? null) {
                    $curriculum->prerequisites()->sync([]);
                    $curriculum->update(['unresolved_prerequisites' => null]);
                }
                continue;
            }

            $curriculum = $curriculumMap[$code] ?? null;
            if (!$curriculum) {
                continue;
            }

            $prereqSubjectIds = [];
            $unresolvedForThis = [];

            foreach ($prerequisites as $prereqRaw) {
                $prereqCode = $this->normalizeCode($prereqRaw);

                // Special case: skip human-readable completion notes
                if (stripos($prereqCode, 'Finished all Academic Requirements') !== false) {
                    $this->command->warn(
                        "[WARN][{$programCode}] Skipping special prerequisite " .
                        "'Finished all Academic Requirements' for {$code}. " .
                        "Represent this as a program-completion rule, not a subject prerequisite."
                    );
                    continue;
                }

                // Resolve within this program's curriculum only — never globally
                $prereqCurriculum = $curriculumMap[$prereqCode] ?? null;

                if (!$prereqCurriculum) {
                    // Record as unresolved — do NOT borrow from another program's map
                    $unresolvedForThis[] = $prereqCode;
                    $this->command->warn(
                        "[WARN][{$programCode}] Unresolved prerequisite '{$prereqCode}' " .
                        "for '{$code}'. Storing in curriculum.unresolved_prerequisites. " .
                        "This subject will be BLOCKED for enrollment until the prerequisite is mapped or corrected."
                    );
                    continue;
                }

                $prereqSubjectIds[] = $prereqCurriculum->subject_id;
            }

            // Persist relational prerequisites (resolved ones)
            $curriculum->prerequisites()->sync(array_unique($prereqSubjectIds));

            // Persist unresolved prerequisite codes to the DB column
            if (!empty($unresolvedForThis)) {
                $unresolvedBySubject[$code] = $unresolvedForThis;

                $curriculum->update([
                    'unresolved_prerequisites' => $unresolvedForThis,
                ]);
            } else {
                // Clear any stale unresolved_prerequisites if re-seeding
                $curriculum->update([
                    'unresolved_prerequisites' => null,
                ]);
            }
        }

        $count = count($rows);
        $this->command->info("[OK] {$programCode}: seeded {$count} curriculum rows.");

        // Print a consolidated summary of all unresolved prerequisites for this program
        if (!empty($unresolvedBySubject)) {
            $this->command->warn("─────────────────────────────────────────────────────────────");
            $this->command->warn("[{$programCode}] UNRESOLVED PREREQUISITES SUMMARY:");
            foreach ($unresolvedBySubject as $subjectCode => $missingCodes) {
                $this->command->warn(
                    "  • {$subjectCode} is missing: " . implode(', ', $missingCodes)
                );
            }
            $this->command->warn(
                "[{$programCode}] These subjects will be blocked at runtime until the " .
                "prerequisite mapping is corrected in {$programCode}CurriculumSeeder.php."
            );
            $this->command->warn("─────────────────────────────────────────────────────────────");
        }
    }
}
