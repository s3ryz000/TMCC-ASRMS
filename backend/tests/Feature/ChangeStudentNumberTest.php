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
        $student = $this->studentWithLogin('A26-0004');
        $student->user->createToken('session');

        $this->change($student, 'A26-0005')
            ->assertOk()
            ->assertJsonPath('message', 'Student number changed from A26-0004 to A26-0005.')
            ->assertJsonPath('student_number', 'A26-0005')
            ->assertJsonPath('previous_number', 'A26-0004')
            ->assertJsonPath('username', 'A26-0005');

        $this->assertDatabaseHas('students', ['student_id' => $student->student_id, 'student_number' => 'A26-0005']);
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'username' => 'A26-0005']);
        $this->assertSame(0, $student->user->tokens()->count());
        $this->assertDatabaseHas('student_number_changes', [
            'student_id' => $student->student_id, 'old_number' => 'A26-0004', 'new_number' => 'A26-0005',
            'old_username' => 'A26-0004', 'new_username' => 'A26-0005', 'source' => 'registrar',
            'reason' => 'Typo on the enrollment form', 'changed_by' => $this->staff->id,
        ]);
        $this->assertDatabaseHas('system_logs', [
            'action' => 'Student number changed: A26-0004 → A26-0005 (Bea Santos). Reason: Typo on the enrollment form',
            'user_id' => $this->staff->id, 'role' => 'staff',
        ]);

        // The old number no longer logs in; the new one does.
        $this->login('A26-0004')->assertStatus(422)->assertJsonValidationErrors(['username' => 'The provided credentials are incorrect.']);
        $this->login('A26-0005')->assertOk()->assertJsonPath('user.username', 'A26-0005');
    }

    public function test_a_reason_and_a_valid_free_number_are_required(): void
    {
        $student = $this->studentWithLogin('A26-0004');
        $this->studentWithLogin('A26-0003', 'Carla');

        $this->change($student, 'A26-0005', null)->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'Give a reason for the change; it is kept in the audit log.']);
        $this->change($student, 'A26-0005', 'x')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->change($student, 'A26-0004')->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => "A26-0004 is already this student's number."]);
        $this->change($student, 'A25-0005')->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => 'The 2 digits after the letter must be 26, the enrollment year.']);
        $this->change($student, '2026-0005')->assertStatus(422)->assertJsonValidationErrors('student_number');

        $this->change($student, 'A26-0003')
            ->assertStatus(422)
            ->assertJsonPath('errors.student_number.0', 'Student number A26-0003 is already used by Carla Santos (BSTM).')
            ->assertJsonPath('conflict.name', 'Carla Santos')
            ->assertJsonPath('conflict.enrollment_date', '2026-08-10');

        $this->assertDatabaseHas('students', ['student_id' => $student->student_id, 'student_number' => 'A26-0004']);
        $this->assertDatabaseHas('users', ['id' => $student->user_id, 'username' => 'A26-0004']);
        $this->assertSame(0, DB::table('student_number_changes')->count());
    }

    public function test_two_wrong_records_are_fixed_one_after_the_other(): void
    {
        // Both records got each other's number; a swap takes two steps.
        $ana = $this->studentWithLogin('A26-0001', 'Ana');
        $ben = $this->studentWithLogin('A26-0002', 'Ben');

        $this->change($ana, 'A26-0002')->assertStatus(422);

        $this->change($ana, 'A26-0009')->assertOk();
        $this->change($ben, 'A26-0001')->assertOk(); // freed a moment ago
        $this->change($ana, 'A26-0002')->assertOk();

        $this->assertSame('A26-0002', $ana->fresh()->student_number);
        $this->assertSame('A26-0001', $ben->fresh()->student_number);
        $this->assertSame(['A26-0002', 'A26-0001'], [$ana->user->fresh()->username, $ben->user->fresh()->username]);
        $this->assertSame(3, DB::table('student_number_changes')->count());
    }

    public function test_the_edit_form_cannot_change_the_number(): void
    {
        $student = $this->studentWithLogin('A26-0004');

        $this->putJson("/api/staff/students/{$student->student_id}", [
            'student_number' => 'A26-0007', 'first_name' => 'Bea', 'last_name' => 'Santos', 'date_of_birth' => '2005-01-01',
            'email' => 'A26-0004@tmcc.test', 'enrollment_date' => '2026-08-10',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_number' => 'Use Change Student Number to change a student number; a reason is required.']);

        $this->assertSame('A26-0004', $student->fresh()->student_number);
    }

    public function test_only_the_registrar_can_change_or_list(): void
    {
        $student = $this->studentWithLogin('A26-0004');

        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->change($student, 'A26-0005')->assertForbidden();
        $this->getJson('/api/staff/student-numbers/mismatches')->assertForbidden();

        Sanctum::actingAs($student->user, ['*']);
        $this->change($student, 'A26-0005')->assertForbidden();
        $this->getJson('/api/staff/student-numbers/mismatches')->assertForbidden();

        $this->assertSame('A26-0004', $student->fresh()->student_number);
    }

    public function test_the_id_check_lists_numbers_that_dont_match_the_enrollment_year(): void
    {
        $this->studentWithLogin('A26-0001');                              // matches
        $graduate = $this->studentWithLogin('A27-0001', 'Gio', '2023-08-29'); // year mismatch
        $legacy = $this->studentWithLogin('TMCC-2026-0009', 'Lea');       // old format

        $this->getJson('/api/staff/student-numbers/mismatches')
            ->assertOk()
            ->assertExactJson(['mismatches' => [
                [
                    'student_id' => $graduate->student_id, 'student_number' => 'A27-0001', 'name' => 'Gio Santos', 'program' => 'BSTM',
                    'enrollment_date' => '2023-08-29', 'expected_prefix' => '23', 'problem' => 'Shows year 27, enrolled in 2023',
                ],
                [
                    'student_id' => $legacy->student_id, 'student_number' => 'TMCC-2026-0009', 'name' => 'Lea Santos', 'program' => 'BSTM',
                    'enrollment_date' => '2026-08-10', 'expected_prefix' => '26', 'problem' => 'Not in the A26-0001 format',
                ],
            ]]);
    }
}
