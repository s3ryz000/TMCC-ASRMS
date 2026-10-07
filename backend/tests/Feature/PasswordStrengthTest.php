<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #87: one password rule wherever a password is set (create user, admin reset,
 * change password): 10+ characters with letters and numbers, not the username
 * or e-mail name, not a common password. Existing passwords keep working.
 */
class PasswordStrengthTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const VALID = 'blue7river42';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->makeUser('admin', 'admin01', 'old-weak');
    }

    /**
     * The username used by every form below is long enough, and mixed enough,
     * that only the "same as the username" check can refuse it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'common word'      => ['password', StrongPassword::HINT],
            'common + numbers' => ['password123', 'This password is too common. Choose one that is harder to guess.'],
            'the username'     => ['JDelaCruz2026', 'The password must not be the same as the username or e-mail name.'],
            '9 characters'     => ['abcd12345', StrongPassword::HINT],
            'letters only'     => ['bluerivergate', StrongPassword::HINT],
            'numbers only'     => ['12345678901', StrongPassword::HINT],
        ];
    }

    // ── Create user ─────────────────────────────────────────────────────────

    private function createUser(string $password)
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->postJson('/api/admin/users', [
            'name'                  => 'Juan Dela Cruz',
            'username'              => 'jdelacruz2026',
            'email'                 => 'juan@tmcc.test',
            'password'              => $password,
            'password_confirmation' => $password,
            'role'                  => 'staff',
        ]);
    }

    #[DataProvider('weakPasswords')]
    public function test_create_user_refuses_a_weak_password(string $password, string $message): void
    {
        $this->createUser($password)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', $message);

        $this->assertDatabaseMissing('users', ['username' => 'jdelacruz2026']);
    }

    public function test_create_user_refuses_the_email_name(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/users', [
            'name' => 'Ana', 'username' => 'ana01', 'email' => 'ana.reyes2026@tmcc.test',
            'password' => 'ana.reyes2026', 'password_confirmation' => 'ana.reyes2026', 'role' => 'staff',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_create_user_accepts_a_strong_password(): void
    {
        $this->createUser(self::VALID)->assertCreated();

        $this->assertTrue(Hash::check(self::VALID, User::where('username', 'jdelacruz2026')->value('password')));
    }

    // ── Admin reset ─────────────────────────────────────────────────────────

    private function resetPassword(string $password)
    {
        $target = User::where('username', 'jdelacruz2026')->first()
            ?? $this->makeUser('staff', 'jdelacruz2026', 'original-pass-1');

        Sanctum::actingAs($this->admin, ['*']);

        return $this->putJson('/api/admin/users/' . $target->id, [
            'name'                  => $target->name,
            'email'                 => $target->email,
            'role'                  => 'staff',
            'status'                => 'active',
            'password'              => $password,
            'password_confirmation' => $password,
        ]);
    }

    #[DataProvider('weakPasswords')]
    public function test_admin_reset_refuses_a_weak_password(string $password, string $message): void
    {
        $this->resetPassword($password)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', $message);

        $this->assertTrue(Hash::check('original-pass-1', User::where('username', 'jdelacruz2026')->value('password')));
    }

    public function test_admin_reset_accepts_a_strong_password(): void
    {
        $this->resetPassword(self::VALID)->assertOk();

        $this->assertTrue(Hash::check(self::VALID, User::where('username', 'jdelacruz2026')->value('password')));
    }

    // ── Change own password ─────────────────────────────────────────────────

    private function changePassword(string $password)
    {
        $user = User::where('username', 'jdelacruz2026')->first()
            ?? $this->makeUser('staff', 'jdelacruz2026', 'original-pass-1');

        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/auth/change-password', [
            'current_password'      => 'original-pass-1',
            'password'              => $password,
            'password_confirmation' => $password,
        ]);
    }

    #[DataProvider('weakPasswords')]
    public function test_change_password_refuses_a_weak_password(string $password, string $message): void
    {
        $this->changePassword($password)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', $message);

        $this->assertTrue(Hash::check('original-pass-1', User::where('username', 'jdelacruz2026')->value('password')));
    }

    public function test_change_password_accepts_a_strong_password(): void
    {
        $this->changePassword(self::VALID)->assertOk();

        $this->assertTrue(Hash::check(self::VALID, User::where('username', 'jdelacruz2026')->value('password')));
    }

    // ── Existing passwords and generated ones ───────────────────────────────

    public function test_an_existing_weak_password_still_signs_in(): void
    {
        $this->makeUser('staff', 'legacy01', 'password123');

        $this->postJson('/api/auth/login', ['username' => 'legacy01', 'password' => 'password123'])->assertOk();
    }

    public function test_generated_student_passwords_always_pass_the_rule(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $password = User::generatePassword('260100', 'ana@tmcc.test');

            $this->assertNull(StrongPassword::problem($password, '260100', 'ana@tmcc.test'), $password);
        }
    }

    public function test_new_student_account_password_passes_the_rule_and_signs_in(): void
    {
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);

        $account = $this->postJson('/api/staff/students', [
            'student_number'  => '260100',
            'first_name'      => 'Ana',
            'last_name'       => 'Reyes',
            'date_of_birth'   => '2006-02-02',
            'email'           => 'ana@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
            'program_id'      => $program->id,
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ])->assertCreated()->json('account');

        $this->assertNull(StrongPassword::problem($account['password'], $account['username'], 'ana@tmcc.test'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', $account)->assertOk();
    }
}
