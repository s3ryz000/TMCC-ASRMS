<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\AcademicStandingService;
use App\Support\SafeLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #58: no student data in storage/logs. A database error's message carries
 * the failing query's bound values; logs record the SQL with placeholders.
 */
class LogContentsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    /** @var string[] everything written to the log during a test */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoStudentRecords();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message . ' ' . json_encode($e->context);
        });
    }

    private function queryFailure(array $values): QueryException
    {
        $pdo = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: students.email');
        $pdo->errorInfo = ['23000', 19, 'UNIQUE constraint failed: students.email'];

        return new QueryException('sqlite', 'insert into "students" ("first_name", "date_of_birth", "address") values (?, ?, ?)', $values, $pdo);
    }

    private function assertLogHolds(string $expected, array $mustNotContain): void
    {
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString($expected, $all);
        foreach ($mustNotContain as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    public function test_a_failed_student_save_is_logged_without_the_students_values(): void
    {
        $values = ['Carmelita', '2006-07-07', 'Purok 5 Hugo Perez'];
        Student::creating(fn () => throw $this->queryFailure($values));
        Sanctum::actingAs($this->registrar, ['*']);

        $this->postJson('/api/staff/students', [
            'student_number' => '0050', 'first_name' => 'Carmelita', 'last_name' => 'Ocampo', 'date_of_birth' => '2006-07-07',
            'email' => 'carmelita@tmcc.test', 'sex' => 'F', 'enrollment_date' => '2026-08-10', 'address' => 'Purok 5 Hugo Perez',
            'program_id' => $this->program->id, 'record_type' => 'Form 137', 'cabinet_no' => 'C1', 'shelf_no' => 'S1',
            'folder_code' => 'F1', 'document_status' => 'Complete',
        ])->assertStatus(500);

        $this->assertLogHolds('Failed to create student and account: QueryException [23000]', array_merge($values, ['Ocampo']));
    }

    public function test_an_unhandled_query_error_is_logged_without_its_values(): void
    {
        $this->mock(AcademicStandingService::class, fn ($mock) => $mock
            ->shouldReceive('getAcademicSummary')
            ->andThrow($this->queryFailure(['Anabel', '2005-02-02', 'Blk 1 Lot 2 Cabezas Trece Martires'])));
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $this->getJson('/api/student/academic-summary')->assertStatus(500);

        $this->assertLogHolds('values (?, ?, ?)', ['Anabel', '2005-02-02', 'Cabezas']);
    }

    public function test_a_quoted_value_in_a_driver_message_is_masked(): void
    {
        $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'anabel@tmcc.test' for key 'students_email_unique'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'anabel@tmcc.test' for key 'students_email_unique'"];
        $e = new QueryException('mysql', 'insert into `students` (`email`) values (?)', ['anabel@tmcc.test'], $pdo);

        $line = SafeLog::describe($e);

        $this->assertStringNotContainsString('anabel@tmcc.test', $line);
        $this->assertSame("QueryException [23000] Duplicate entry '?' for key '?' SQL: insert into `students` (`email`) values (?)", $line);
    }
}
