<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #83: GET /api/student/cor defaulted the semester to the text
 * "2nd Semester" while enrollments store 1 or 2, so it never returned any
 * subjects. It now returns the student's latest term, comparing semesters
 * normalised.
 */
class StudentCorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $account;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $program = $this->makeProgram();
        $this->makeCurriculum($program, ['A' => [1, 1], 'B' => [1, 1], 'C' => [1, 2], 'D' => [1, 2], 'E' => [1, 2]]);
        $this->account = $this->makeUser('student', '260001');
        $this->student = $this->makeStudent($program, ['student_number' => '260001'], $this->account);

        $this->enroll('A', '2026-2027', '1', 'Passed');
        $this->enroll('B', '2026-2027', '1', 'Passed');
        $this->enroll('C', '2026-2027', '2', 'Enrolled');
        $this->enroll('D', '2026-2027', '2nd Semester', 'Enrolled'); // older rows may spell it out
        $this->enroll('E', '2026-2027', '2', 'Cancelled');

        Sanctum::actingAs($this->account, ['*']);
    }

    private function enroll(string $code, string $ay, string $semester, string $status): void
    {
        Enrollment::create([
            'student_id' => $this->student->student_id, 'subject_id' => $this->subjects[$code]->id,
            'academic_year' => $ay, 'semester' => $semester, 'year_level' => 1, 'status' => $status,
        ]);
    }

    private function codes($response): array
    {
        return collect($response->json('enrollments'))->pluck('subject.code')->all();
    }

    public function test_without_a_term_it_returns_the_latest_terms_subjects(): void
    {
        $response = $this->getJson('/api/student/cor')
            ->assertOk()
            ->assertJsonPath('academic_year', '2026-2027')
            ->assertJsonPath('semester', 2);

        $this->assertSame(['C', 'D'], $this->codes($response), 'Cancelled enrollments are not registered subjects.');
    }

    public function test_a_requested_term_matches_however_the_semester_is_written(): void
    {
        foreach (['1', '1st', '1st Semester'] as $semester) {
            $response = $this->getJson('/api/student/cor?' . http_build_query(['academic_year' => '2026-2027', 'semester' => $semester]))
                ->assertOk()
                ->assertJsonPath('semester', 1);
            $this->assertSame(['A', 'B'], $this->codes($response), $semester);
        }
    }

    public function test_an_unknown_semester_is_refused(): void
    {
        $this->getJson('/api/student/cor?semester=summer')->assertStatus(422)->assertJsonValidationErrors('semester');
    }

    public function test_a_student_with_no_enrollments_gets_an_empty_list(): void
    {
        Enrollment::query()->delete();

        $this->getJson('/api/student/cor')->assertOk()->assertJsonPath('enrollments', [])->assertJsonPath('semester', null);
    }

    public function test_only_the_signed_in_students_subjects_are_listed(): void
    {
        $other = $this->makeStudent($this->student->program()->first(), ['student_number' => '260002', 'email' => 'o@tmcc.test']);
        Enrollment::create([
            'student_id' => $other->student_id, 'subject_id' => $this->subjects['E']->id,
            'academic_year' => '2026-2027', 'semester' => '2', 'year_level' => 1, 'status' => 'Enrolled',
        ]);

        $this->assertSame(['C', 'D'], $this->codes($this->getJson('/api/student/cor')->assertOk()));
    }
}
