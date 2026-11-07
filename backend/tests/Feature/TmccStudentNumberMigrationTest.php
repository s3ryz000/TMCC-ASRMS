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
 * #56: the reversible conversion of YY+4 student numbers (260001) and their
 * login usernames to TMCC's format (A26-0001). Only numbers and usernames
 * change, and rolling it back leaves the earlier YY+4 conversion's log alone.
 */
class TmccStudentNumberMigrationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private object $migration;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->migration = require database_path('migrations/2026_11_07_000001_convert_student_numbers_to_tmcc_format.php');
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

    private function runUp(): void
    {
        ob_start();
        try {
            $this->migration->up();
        } finally {
            ob_end_clean();
        }
    }

    public function test_up_converts_numbers_and_usernames_and_down_restores_them(): void
    {
        $first = $this->demoStudent('260001');
        $this->demoStudent('260002');
        $this->demoStudent('270001', '2027-01-10');
        // A login that isn't the student number keeps its username.
        $this->demoStudent('250013', '2025-06-01', 'juan.student');
        $this->demoStudent('A25-0009', '2025-06-01'); // already converted

        // A row the earlier YY+4 conversion logged: down() here must keep it.
        DB::table('student_number_changes')->insert([
            'student_id' => $first->student_id, 'old_number' => 'TMCC-2026-0001', 'new_number' => '260001',
            'source' => 'migration', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $oldNumbers = $this->numbers();
        $oldUsernames = $this->usernames();
        $records = $this->recordCounts();

        $this->runUp();

        $this->assertSame(['A26-0001', 'A26-0002', 'A27-0001', 'A25-0013', 'A25-0009'], $this->numbers());
        $this->assertSame(['A26-0001', 'A26-0002', 'A27-0001', 'juan.student', 'A25-0009'], $this->usernames());
        $this->assertSame($records, $this->recordCounts());
        $this->assertSame(4, DB::table('student_number_changes')->whereNotNull('reason')->count());
        $this->assertDatabaseHas('student_number_changes', [
            'old_number' => '260002', 'new_number' => 'A26-0002', 'old_username' => '260002', 'new_username' => 'A26-0002', 'source' => 'migration',
        ]);
        $this->assertDatabaseHas('student_number_changes', [
            'old_number' => '250013', 'new_number' => 'A25-0013', 'old_username' => null, 'new_username' => null,
        ]);

        // Grades still belong to the same student.
        $this->assertSame(1, Student::where('student_number', 'A26-0001')->firstOrFail()->grades()->count());

        // Running it again changes nothing.
        $this->runUp();
        $this->assertSame(4, DB::table('student_number_changes')->whereNotNull('reason')->count());

        $this->migration->down();

        $this->assertSame($oldNumbers, $this->numbers());
        $this->assertSame($oldUsernames, $this->usernames());
        $this->assertSame($records, $this->recordCounts());
        $this->assertSame(1, DB::table('student_number_changes')->count());
        $this->assertDatabaseHas('student_number_changes', ['old_number' => 'TMCC-2026-0001', 'new_number' => '260001']);
    }

    public function test_an_unknown_format_aborts_and_changes_nothing(): void
    {
        $this->demoStudent('260001');
        $this->demoStudent('2026-ABC');
        $numbers = $this->numbers();

        try {
            $this->runUp();
            $this->fail('An unknown format should abort the conversion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Not in the YY+4 format: 2026-ABC', $e->getMessage());
        }

        $this->assertSame($numbers, $this->numbers());
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }

    public function test_a_collision_aborts_and_changes_nothing(): void
    {
        $this->demoStudent('260003');
        $this->demoStudent('A26-0003');
        $this->demoStudent('260004');
        $this->makeUser('admin', 'A26-0004'); // a login already holding the new number
        $numbers = $this->numbers();
        $usernames = $this->usernames();

        try {
            $this->runUp();
            $this->fail('A collision should abort the conversion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('A26-0003 <- 260003 (A26-0003 is already in use)', $e->getMessage());
            $this->assertStringContainsString('A26-0004 <- 260004 (A26-0004 is already in use)', $e->getMessage());
        }

        $this->assertSame($numbers, $this->numbers());
        $this->assertSame($usernames, $this->usernames());
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }
}
