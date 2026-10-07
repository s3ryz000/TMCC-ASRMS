<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #92: the student's curriculum showed an archived subject (no longer
 * offered, #68) as "Eligible to Take". It is now marked archived and, when
 * not yet taken, has the status "Archived".
 */
class StudentArchivedSubjectTest extends TestCase
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
        $this->makeCurriculum($program, ['A' => [1, 1], 'B' => [1, 1], 'C' => [1, 2]]);
        $this->account = $this->makeUser('student', '260001');
        $this->student = $this->makeStudent($program, ['student_number' => '260001'], $this->account);

        $this->subjects['B']->forceFill(['archived_at' => now()])->save();
        $this->subjects['C']->forceFill(['archived_at' => now()])->save();
        $this->recordGrade($this->student, 'C', '2026-2027', 2, 1.75, 'Passed');

        Sanctum::actingAs($this->account, ['*']);
    }

    private function roadmap(): array
    {
        return collect($this->getJson('/api/student/academic-summary')->assertOk()->json('curriculum.roadmap'))
            ->keyBy('subject_code')
            ->all();
    }

    public function test_an_archived_subject_not_yet_taken_is_archived_not_eligible(): void
    {
        $roadmap = $this->roadmap();

        $this->assertTrue($roadmap['B']['archived']);
        $this->assertSame('Archived', $roadmap['B']['status']);
        $this->assertNotSame('Eligible to Take', $roadmap['B']['status']);
    }

    public function test_a_passed_archived_subject_keeps_its_grade_and_is_marked(): void
    {
        $roadmap = $this->roadmap();

        $this->assertTrue($roadmap['C']['archived']);
        $this->assertSame('Completed', $roadmap['C']['status']);
        $this->assertEquals(1.75, $roadmap['C']['grade']);
    }

    public function test_other_subjects_are_unchanged(): void
    {
        $roadmap = $this->roadmap();

        $this->assertFalse($roadmap['A']['archived']);
        $this->assertSame('Eligible to Take', $roadmap['A']['status']);
    }

    public function test_the_curriculum_endpoint_marks_archived_subjects(): void
    {
        $rows = collect($this->getJson('/api/student/curriculum')->assertOk()->json('curriculum'))->keyBy('subject.code');

        $this->assertTrue($rows['B']['subject']['archived']);
        $this->assertFalse($rows['A']['subject']['archived']);
    }
}
