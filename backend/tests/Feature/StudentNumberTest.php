<?php

namespace Tests\Feature;

use App\Models\ArchiveRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentNumber;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #56: student numbers are the 2-digit enrollment year plus 4 digits
 * (260001). A taken number is refused with the holder's details, and two
 * saves of one number can't both succeed.
 */
class StudentNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $account = $this->makeUser('student', '260003');
        $this->holder = $this->makeStudent($this->program, [
            'student_number' => '260003', 'first_name' => 'Carla', 'last_name' => 'Mendoza',
            'date_of_birth' => '2005-04-12', 'enrollment_date' => '2026-08-10', 'email' => 'carla@tmcc.test',
        ], $account);
        $this->makeStudent($this->program, ['student_number' => '260001', 'email' => 'first@tmcc.test']);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_number'  => '0004',
            'first_name'      => 'Bea',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2006-03-03',
            'email'           => 'bea@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-08-10',
            'program_id'      => $this->program->id,
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ], $overrides);
    }

    private function counts(): array
    {
        return [Student::count(), User::count(), ArchiveRecord::count(), DB::table('system_logs')->count()];
    }

    // -------------------------------------------------------------- rule

    public function test_the_year_prefix_comes_from_the_plain_enrollment_date(): void
    {
        $this->assertSame('26', StudentNumber::yearPrefix('2026-08-10'));
        $this->assertSame('26', StudentNumber::yearPrefix('2026-01-01 00:00:00'));
        $this->assertSame('27', StudentNumber::yearPrefix(Carbon::parse('2027-12-31')));
        $this->assertNull(StudentNumber::yearPrefix('next year'));
        $this->assertTrue(StudentNumber::isValid('260001'));
        foreach (['TMCC-2026-0001', '26001', '2600011', '26 0001', 'abcdef', ''] as $bad) {
            $this->assertFalse(StudentNumber::isValid($bad), $bad);
        }
    }

    public function test_the_four_digit_part_is_stored_with_the_year_prefix(): void
    {
        $this->postJson('/api/staff/students', $this->payload())
            ->assertCreated()
            ->assertJsonPath('student.student_number', '260004')
            ->assertJsonPath('account.username', '260004');

        $this->assertDatabaseHas('users', ['username' => '260004', 'email' => 'bea@tmcc.test']);

        // The full 6 digits are accepted as well.
        $this->postJson('/api/staff/students', $this->payload(['student_number' => '260010', 'email' => 'ten@tmcc.test']))
            ->assertCreated()
            ->assertJsonPath('student.student_number', '260010');
    }

    public function test_a_wrong_format_or_year_is_refused(): void
    {
        $before = $this->counts();

        foreach (['TMCC-2026-0004', '26004', '2600045', 'abcd', '004'] as $bad) {
            $this->postJson('/api/staff/students', $this->payload(['student_number' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['student_number' => StudentNumber::FORMAT_MESSAGE]);
        }
        $this->postJson('/api/staff/students', $this->payload(['student_number' => '250004']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => 'The first two digits must be 26, the enrollment year.']);

        $this->assertSame($before, $this->counts());
    }

    // --------------------------------------------------------- duplicates

    public function test_a_taken_number_is_refused_with_the_holders_details(): void
    {
        $before = $this->counts();

        $this->postJson('/api/staff/students', $this->payload(['student_number' => '0003']))
            ->assertStatus(422)
            ->assertJsonPath('errors.student_number.0', 'Student number 260003 is already used by Carla Mendoza (BSTM).')
            ->assertJsonPath('conflict', [
                'student_number'  => '260003',
                'name'            => 'Carla Mendoza',
                'program'         => 'BSTM',
                'enrollment_date' => '2026-08-10',
                'date_of_birth'   => '2005-04-12',
            ])
            ->assertJsonPath('next_available', '260004');

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseMissing('users', ['email' => 'bea@tmcc.test']);
    }

    public function test_a_number_used_only_as_a_login_is_taken_too(): void
    {
        $this->makeUser('admin', '260020');

        $this->postJson('/api/staff/students', $this->payload(['student_number' => '0020']))
            ->assertStatus(422)
            ->assertJsonPath('errors.student_number.0', 'Student number 260020 is already used by another account.')
            ->assertJsonPath('conflict', ['student_number' => '260020', 'name' => null, 'program' => null]);
    }

    public function test_two_saves_of_one_number_cannot_both_succeed(): void
    {
        // Another registrar saves 260004 between this request's availability
        // check and its insert; the unique index refuses the second insert.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced) {
            if ($raced || ! str_contains($query->sql, '"username" = ?') || ($query->bindings[0] ?? null) !== '260004') {
                return;
            }
            $raced = true;
            $userId = DB::table('users')->insertGetId([
                'name' => 'Dan Cruz', 'email' => 'dan@tmcc.test', 'username' => '260004', 'role' => 'student',
                'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('students')->insert([
                'user_id' => $userId, 'program_id' => $this->program->id, 'student_number' => '260004',
                'first_name' => 'Dan', 'last_name' => 'Cruz', 'date_of_birth' => '2006-01-01', 'email' => 'dan@tmcc.test',
                'sex' => 'M', 'enrollment_date' => '2026-08-10', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $before = $this->counts();

        $this->postJson('/api/staff/students', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('conflict.name', 'Dan Cruz')
            ->assertJsonPath('next_available', '260005');

        $this->assertTrue($raced);
        // Only the racing save is there: one student and one account more, none of this request's rows.
        [$students, $users, $archives] = $before;
        $this->assertSame([$students + 1, $users + 1, $archives], array_slice($this->counts(), 0, 3));
        $this->assertDatabaseMissing('users', ['email' => 'bea@tmcc.test']);
        $this->assertSame(1, Student::where('student_number', '260004')->count());
    }

    // ------------------------------------------------------- check endpoint

    public function test_the_check_reports_availability_and_the_next_number(): void
    {
        $this->getJson('/api/staff/student-numbers/check?number=260002')
            ->assertOk()
            ->assertExactJson(['number' => '260002', 'available' => true, 'next_available' => '260004']);

        // A taken number shows only the holder's name and program, nothing else.
        $this->getJson('/api/staff/student-numbers/check?number=260003')
            ->assertOk()
            ->assertExactJson([
                'number' => '260003', 'available' => false, 'next_available' => '260004',
                'conflict' => ['student_number' => '260003', 'name' => 'Carla Mendoza', 'program' => 'BSTM'],
            ]);

        $this->getJson('/api/staff/student-numbers/check?number=270050')->assertOk()->assertJsonPath('next_available', '270001');
        $this->getJson('/api/staff/student-numbers/check?number=2026-0001')->assertStatus(422)->assertJsonValidationErrors('number');
    }

    public function test_next_available_fills_gaps_once_the_year_is_full(): void
    {
        $this->assertSame('260004', StudentNumber::nextAvailable('26'));

        $this->makeStudent($this->program, ['student_number' => '269999', 'email' => 'last@tmcc.test']);
        $this->assertSame('260002', StudentNumber::nextAvailable('26'));
        $this->assertSame('300001', StudentNumber::nextAvailable('30'));
    }

    public function test_only_the_registrar_can_check_or_create(): void
    {
        $admin = $this->makeUser('admin');
        $before = $this->counts();

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/staff/student-numbers/check?number=260002')->assertForbidden();
        $this->postJson('/api/staff/students', $this->payload())->assertForbidden();

        Sanctum::actingAs(User::where('username', '260003')->firstOrFail(), ['*']);
        $this->getJson('/api/staff/student-numbers/check?number=260002')->assertForbidden();
        $this->postJson('/api/staff/students', $this->payload())->assertForbidden();

        $this->assertSame($before, $this->counts());
    }
}
