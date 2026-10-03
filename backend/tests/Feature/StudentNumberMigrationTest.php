<?php

namespace Tests\Feature;

use App\Models\ArchiveRecord;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #56: the reversible conversion of existing student numbers and their login
 * usernames to the YY+4 format. Only numbers and usernames change.
 */
class StudentNumberMigrationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private object $migration;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->migration = require database_path('migrations/2026_10_03_000007_convert_student_numbers_to_yy_format.php');
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($this->program, ['GEC4' => [1, 1]]);
    }

    /** A student whose login username is the number, with an enrollment, a grade and an archive record. */
    private function demoStudent(string $number, string $enrolled = '2026-08-10', ?string $username = null): Student
    {
        $account = $this->makeUser('student', $username ?? $number);
        $student = $this->makeStudent($this->program, [
            'student_number' => $number, 'email' => strtolower(str_replace('-', '', $number)) . '@tmcc.test', 'enrollment_date' => $enrolled,
        ], $account);
        $enrollment = Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $this->subjects['GEC4']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'year_level' => 1, 'status' => 'Passed',
        ]);
        $this->recordGrade($student, 'GEC4', '2026-2027', 1, 1.5, 'Passed')->update(['enrollment_id' => $enrollment->id]);
        ArchiveRecord::create([
            'student_id' => $student->student_id, 'record_type' => 'Form 137', 'cabinet_no' => 'C1',
            'shelf_no' => 'S1', 'folder_code' => 'F1', 'document_status' => 'Complete',
        ]);

        return $student;
    }

    private function numbers(): array
    {
        return Student::orderBy('student_id')->pluck('student_number')->all();
    }

    private function usernames(): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'student'))->orderBy('id')->pluck('username')->all();
    }

    private function recordCounts(): array
    {
        return [
            Student::count(), Enrollment::withTrashed()->count(), DB::table('grades')->count(),
            ArchiveRecord::count(), DB::table('record_requests')->count(), DB::table('student_documents')->count(),
        ];
    }

    public function test_up_converts_numbers_and_usernames_and_down_restores_them(): void
    {
        $this->demoStudent('TMCC-2026-0001');
        $this->demoStudent('TMCC-2026-0002');
        $this->demoStudent('TMCC-2026-0003');
        $this->demoStudent('STU-2027-001', '2023-08-29');
        $this->demoStudent('TMCC-2025-001', '2025-06-01');
        // A login that isn't the student number keeps its username.
        $this->demoStudent('TMCC-2024-0007', '2024-06-01', 'juan.student');
        $this->demoStudent('260050'); // already converted

        $oldNumbers = $this->numbers();
        $oldUsernames = $this->usernames();
        $records = $this->recordCounts();

        ob_start();
        $this->migration->up();
        ob_end_clean();

        $this->assertSame(['260001', '260002', '260003', '270001', '250001', '240007', '260050'], $this->numbers());
        $this->assertSame(['260001', '260002', '260003', '270001', '250001', 'juan.student', '260050'], $this->usernames());
        $this->assertSame($records, $this->recordCounts());
        $this->assertSame(6, DB::table('student_number_changes')->where('source', 'migration')->count());
        $this->assertDatabaseHas('student_number_changes', [
            'old_number' => 'STU-2027-001', 'new_number' => '270001', 'old_username' => 'STU-2027-001', 'new_username' => '270001',
        ]);
        $this->assertDatabaseHas('student_number_changes', [
            'old_number' => 'TMCC-2024-0007', 'new_number' => '240007', 'old_username' => null, 'new_username' => null,
        ]);

        // Grades and enrollments still belong to the same students.
        $this->assertSame(1, Student::where('student_number', '260003')->firstOrFail()->grades()->count());

        $this->migration->down();

        $this->assertSame($oldNumbers, $this->numbers());
        $this->assertSame($oldUsernames, $this->usernames());
        $this->assertSame($records, $this->recordCounts());
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }

    public function test_an_unknown_format_aborts_and_changes_nothing(): void
    {
        $this->demoStudent('TMCC-2026-0001');
        $this->demoStudent('2026-ABC');
        $numbers = $this->numbers();

        try {
            $this->migration->up();
            $this->fail('An unknown format should abort the conversion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Not a known old format: 2026-ABC', $e->getMessage());
        }

        $this->assertSame($numbers, $this->numbers());
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }

    public function test_a_collision_aborts_and_changes_nothing(): void
    {
        $this->demoStudent('TMCC-2026-0003');
        $this->demoStudent('260003');
        $this->demoStudent('TMCC-2026-005');
        $this->demoStudent('TMCC-2026-0005');
        $numbers = $this->numbers();
        $usernames = $this->usernames();

        try {
            $this->migration->up();
            $this->fail('A collision should abort the conversion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('260003 <- TMCC-2026-0003 (260003 is already in use)', $e->getMessage());
            $this->assertStringContainsString('260005 <- TMCC-2026-005, TMCC-2026-0005', $e->getMessage());
        }

        $this->assertSame($numbers, $this->numbers());
        $this->assertSame($usernames, $this->usernames());
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }
}
