<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicResidencyValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #91: a first-year student with 3 terms on record (demo student 260003) was
 * shown "Enrollment Ineligible – Maximum Residency Period Reached". The term
 * count used ->select(year_level, semester)->distinct()->count(), which
 * Laravel runs as "select distinct count(*)": every enrollment row (one per
 * subject) was counted as a term.
 */
class ResidencyStatusTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();

        // Seven subjects in each of the first three terms, and more after.
        $defs = [];
        foreach ([[1, 1], [1, 2], [2, 1], [2, 2]] as [$year, $sem]) {
            for ($i = 1; $i <= 7; $i++) {
                $defs["Y{$year}S{$sem}N{$i}"] = [$year, $sem];
            }
        }
        $this->makeCurriculum($this->program, $defs);

        $this->account = $this->makeUser('student', '260003');
        $this->student = $this->makeStudent($this->program, ['student_number' => '260003'], $this->account);
    }

    /** One Enrolled row per subject of the term, as the enrollment flows write them. */
    private function enrollTerm(int $year, int|string $semester, string $academicYear, ?int $storedYear = null): void
    {
        $sem = is_int($semester) ? $semester : (int) $semester[0];
        foreach (array_keys($this->subjects) as $code) {
            if (! str_starts_with($code, "Y{$year}S{$sem}")) {
                continue;
            }
            Enrollment::create([
                'student_id'    => $this->student->student_id,
                'subject_id'    => $this->subjects[$code]->id,
                'academic_year' => $academicYear,
                'semester'      => (string) $semester,
                'year_level'    => $storedYear ?? $year,
                'status'        => 'Enrolled',
            ]);
        }
    }

    private function residency(): array
    {
        return app(AcademicResidencyValidationService::class)->computeResidency($this->student->fresh());
    }

    public function test_a_first_year_student_with_three_terms_is_within_the_regular_program(): void
    {
        $this->enrollTerm(1, 1, '2026-2027');
        $this->enrollTerm(1, 2, '2026-2027');
        $this->enrollTerm(2, 1, '2027-2028');

        $residency = $this->residency();

        $this->assertSame(3, $residency['used_regular_terms']);
        $this->assertSame(4, $residency['next_term_number']);
        $this->assertSame('within_regular_program', $residency['residency_status']);
    }

    public function test_the_student_dashboard_no_longer_shows_the_false_warning(): void
    {
        $this->enrollTerm(1, 1, '2026-2027');
        $this->enrollTerm(1, 2, '2026-2027');
        $this->enrollTerm(2, 1, '2027-2028');

        Sanctum::actingAs($this->account, ['*']);

        $this->getJson('/api/student/academic-summary')
            ->assertOk()
            ->assertJsonPath('residency.residency_status', 'within_regular_program')
            ->assertJsonPath('residency.used_regular_terms', 3);
    }

    public function test_the_registrar_next_term_view_is_not_blocked_either(): void
    {
        $this->enrollTerm(1, 1, '2026-2027');
        $this->enrollTerm(1, 2, '2026-2027');
        $this->enrollTerm(2, 1, '2027-2028');

        Sanctum::actingAs($this->makeUser('staff'), ['*']);

        $this->assertArrayNotHasKey('ineligible_max_residency', $this->academicProgress($this->student)['next_allowed_term']);
    }

    public function test_the_same_term_written_two_ways_counts_once(): void
    {
        $this->enrollTerm(1, 1, '2026-2027');
        Enrollment::where('subject_id', $this->subjects['Y1S1N1']->id)->update(['semester' => '1st Semester']);

        $this->assertSame(1, $this->residency()['used_regular_terms']);
    }

    public function test_a_genuine_eleventh_term_is_still_refused(): void
    {
        // Eight regular terms and both fifth-year terms used: the next would be the 11th.
        foreach ([1, 2, 3, 4] as $year) {
            foreach ([1, 2] as $sem) {
                $code = $year <= 2 ? "Y{$year}S{$sem}N1" : 'Y1S1N1';
                Enrollment::create([
                    'student_id' => $this->student->student_id, 'subject_id' => $this->subjects[$code]->id,
                    'academic_year' => (2025 + $year) . '-' . (2026 + $year), 'semester' => (string) $sem,
                    'year_level' => $year, 'status' => 'Enrolled',
                ]);
            }
        }
        foreach ([1, 2] as $sem) {
            Enrollment::create([
                'student_id' => $this->student->student_id, 'subject_id' => $this->subjects['Y1S1N2']->id,
                'academic_year' => '2030-2031', 'semester' => (string) $sem, 'year_level' => 5, 'status' => 'Enrolled',
            ]);
        }

        $residency = $this->residency();

        $this->assertSame(8, $residency['used_regular_terms']);
        $this->assertSame(2, $residency['used_fifth_year_terms']);
        $this->assertSame('ineligible_max_residency', $residency['residency_status']);
    }

    public function test_the_student_dashboard_returns_the_request_summary(): void
    {
        foreach (['pending', 'pending', 'approved', 'rejected', 'released'] as $status) {
            RecordRequest::create([
                'student_id' => $this->student->student_id, 'record_type' => 'transcript', 'purpose' => 'Employment',
                'copies' => 1, 'status' => $status, 'requested_at' => now(),
            ]);
        }
        $other = $this->makeStudent($this->program, ['student_number' => '260004', 'email' => 'other@tmcc.test']);
        RecordRequest::create([
            'student_id' => $other->student_id, 'record_type' => 'transcript', 'purpose' => 'Employment',
            'copies' => 1, 'status' => 'pending', 'requested_at' => now(),
        ]);

        Sanctum::actingAs($this->account, ['*']);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis', ['pending_requests' => 2, 'approved' => 1, 'rejected' => 1, 'released' => 1]);
    }
}
