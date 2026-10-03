<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #56: Change Student Number. Registrar only, reason required, the login
 * username follows atomically, the old login stops working, the change is
 * audited; the ID check lists numbers that don't match the enrollment year.
 */
class ChangeStudentNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function studentWithLogin(string $number, string $first = 'Bea', string $enrolled = '2026-08-10'): Student
    {
        return $this->makeStudent($this->program, [
            'student_number' => $number, 'first_name' => $first, 'last_name' => 'Santos',
            'email' => "{$number}@tmcc.test", 'enrollment_date' => $enrolled,
        ], $this->makeUser('student', $number));
    }

    private function change(Student $student, ?string $number, ?string $reason = 'Typo on the enrollment form')
    {
        return $this->patchJson("/api/staff/students/{$student->student_id}/student-number", array_filter([
            'student_number' => $number, 'reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    private function login(string $username)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['username' => $username, 'password' => 'secret-pass']);
    }

    public function test_a_change_moves_the_login_and_is_audited(): void
    {
        $student = $this->studentWithLogin('260004');
        $student->user->createToken('session');

        $this->change($student, '260005')
            ->assertOk()
            ->assertJsonPath('message', 'Student number changed from 260004 to 260005.')
            ->assertJsonPath('student_number', '260005')
            ->assertJsonPath('previous_number', '260004')
            ->assertJsonPath('username', '260005');

        $this->assertDatabaseHas('students', ['student_id' => $student->student_id, 'student_number' => '260005']);
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'username' => '260005']);
        $this->assertSame(0, $student->user->tokens()->count());
        $this->assertDatabaseHas('student_number_changes', [
            'student_id' => $student->student_id, 'old_number' => '260004', 'new_number' => '260005',
            'old_username' => '260004', 'new_username' => '260005', 'source' => 'registrar',
            'reason' => 'Typo on the enrollment form', 'changed_by' => $this->staff->id,
        ]);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Student number changed: 260004 → 260005 (Bea Santos). Reason: Typo on the enrollment form',
            'user_id' => $this->staff->id, 'role' => 'staff',
        ]);

        // The old number no longer logs in; the new one does.
        $this->login('260004')->assertStatus(422)->assertJsonValidationErrors(['username' => 'The provided credentials are incorrect.']);
        $this->login('260005')->assertOk()->assertJsonPath('user.username', '260005');
    }

    public function test_a_reason_and_a_valid_free_number_are_required(): void
    {
        $student = $this->studentWithLogin('260004');
        $this->studentWithLogin('260003', 'Carla');

        $this->change($student, '260005', null)->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Give a reason for the change; it is kept in the audit log.']);
        $this->change($student, '260005', 'x')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->change($student, '260004')->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => "260004 is already this student's number."]);
        $this->change($student, '250005')->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => 'The first two digits must be 26, the enrollment year.']);
        $this->change($student, '2026-0005')->assertStatus(422)->assertJsonValidationErrors('student_number');

        $this->change($student, '260003')
            ->assertStatus(422)
            ->assertJsonPath('errors.student_number.0', 'Student number 260003 is already used by Carla Santos (BSTM).')
            ->assertJsonPath('conflict.name', 'Carla Santos')
            ->assertJsonPath('conflict.enrollment_date', '2026-08-10');

        $this->assertDatabaseHas('students', ['student_id' => $student->student_id, 'student_number' => '260004']);
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'username' => '260004']);
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }

    public function test_two_wrong_records_are_fixed_one_after_the_other(): void
    {
        // Both records got each other's number; a swap takes two steps.
        $ana = $this->studentWithLogin('260001', 'Ana');
        $ben = $this->studentWithLogin('260002', 'Ben');

        $this->change($ana, '260002')->assertStatus(422);

        $this->change($ana, '260009')->assertOk();
        $this->change($ben, '260001')->assertOk(); // freed a moment ago
        $this->change($ana, '260002')->assertOk();

        $this->assertSame('260002', $ana->fresh()->student_number);
        $this->assertSame('260001', $ben->fresh()->student_number);
        $this->assertSame(['260002', '260001'], [$ana->user->fresh()->username, $ben->user->fresh()->username]);
        $this->assertSame(3, DB::table('student_number_changes')->count());
    }

    public function test_the_edit_form_cannot_change_the_number(): void
    {
        $student = $this->studentWithLogin('260004');

        $this->putJson("/api/staff/students/{$student->student_id}", [
            'student_number' => '260007', 'first_name' => 'Bea', 'last_name' => 'Santos', 'date_of_birth' => '2005-01-01',
            'email' => '260004@tmcc.test', 'enrollment_date' => '2026-08-10',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => 'Use Change Student Number to change a student number; a reason is required.']);

        $this->assertSame('260004', $student->fresh()->student_number);
    }

    public function test_only_the_registrar_can_change_or_list(): void
    {
        $student = $this->studentWithLogin('260004');

        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->change($student, '260005')->assertForbidden();
        $this->getJson('/api/staff/student-numbers/mismatches')->assertForbidden();

        Sanctum::actingAs($student->user, ['*']);
        $this->change($student, '260005')->assertForbidden();
        $this->getJson('/api/staff/student-numbers/mismatches')->assertForbidden();

        $this->assertSame('260004', $student->fresh()->student_number);
    }

    public function test_the_id_check_lists_numbers_that_dont_match_the_enrollment_year(): void
    {
        $this->studentWithLogin('260001');                              // matches
        $graduate = $this->studentWithLogin('270001', 'Gio', '2023-08-29'); // year mismatch
        $legacy = $this->studentWithLogin('TMCC-2026-0009', 'Lea');       // old format

        $this->getJson('/api/staff/student-numbers/mismatches')
            ->assertOk()
            ->assertExactJson(['mismatches' => [
                [
                    'student_id' => $graduate->student_id, 'student_number' => '270001', 'name' => 'Gio Santos', 'program' => 'BSTM',
                    'enrollment_date' => '2023-08-29', 'expected_prefix' => '23', 'problem' => 'Starts with 27, enrolled in 2023',
                ],
                [
                    'student_id' => $legacy->student_id, 'student_number' => 'TMCC-2026-0009', 'name' => 'Lea Santos', 'program' => 'BSTM',
                    'enrollment_date' => '2026-08-10', 'expected_prefix' => '26', 'problem' => 'Not in the YY+4 format',
                ],
            ]]);
    }
}
