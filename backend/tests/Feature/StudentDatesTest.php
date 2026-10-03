<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #77: student dates are calendar dates. The API returns them as "Y-m-d", so
 * saving the Edit Student form with the values it was given changes nothing.
 * They used to come back as a UTC timestamp ("2006-06-20T16:00:00Z" for
 * Manila 21 Jun), which the page turned into the previous day on every save.
 */
class StudentDatesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->student = $this->makeStudent($this->program, [
            'date_of_birth'   => '2006-06-21',
            'enrollment_date' => '2025-08-01',
            'graduation_date' => '2029-05-30',
        ]);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function fetch(): array
    {
        return $this->getJson("/api/staff/students/{$this->student->student_id}")->assertOk()->json('student');
    }

    /** What the Edit Student form submits: the fields it was given, unchanged. */
    private function saveAsIs(array $student): void
    {
        $fields = ['student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'sex', 'email',
            'contact_number', 'address', 'enrollment_date', 'graduation_date'];

        $this->putJson("/api/staff/students/{$this->student->student_id}", array_intersect_key($student, array_flip($fields)))
            ->assertOk();
    }

    public function test_dates_are_returned_as_calendar_dates(): void
    {
        $student = $this->fetch();

        $this->assertSame('2006-06-21', $student['date_of_birth']);
        $this->assertSame('2025-08-01', $student['enrollment_date']);
        $this->assertSame('2029-05-30', $student['graduation_date']);
    }

    public function test_saving_the_form_unchanged_twice_keeps_every_date(): void
    {
        $this->saveAsIs($this->fetch());
        $this->saveAsIs($this->fetch());

        $student = $this->fetch();
        $this->assertSame('2006-06-21', $student['date_of_birth']);
        $this->assertSame('2025-08-01', $student['enrollment_date']);
        $this->assertSame('2029-05-30', $student['graduation_date']);

        $fresh = $this->student->fresh();
        $this->assertSame('2006-06-21', $fresh->date_of_birth->toDateString());
        $this->assertSame('2025-08-01', $fresh->enrollment_date->toDateString());
    }

    public function test_an_unchanged_save_logs_no_changed_fields(): void
    {
        $this->saveAsIs($this->fetch());

        $log = SystemLog::where('action', 'like', "Student {$this->student->student_number} %record updated%")->latest('log_id')->value('action');
        $this->assertNotNull($log);
        $this->assertStringContainsString('fields: no changes', $log);
    }

    public function test_the_academic_summary_also_returns_a_calendar_date(): void
    {
        $this->getJson("/api/staff/students/{$this->student->student_id}/academic-summary")
            ->assertOk()
            ->assertJsonPath('student.enrollment_date', '2025-08-01');
    }
}
