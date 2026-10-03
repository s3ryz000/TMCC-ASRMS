<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Login, logout and password change, exercised with real bearer tokens rather
 * than Sanctum::actingAs so the token lifecycle itself is under test.
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->makeUser('staff', 'registrar01', 'correct-horse');
    }

    private function login(string $username = 'registrar01', string $password = 'correct-horse')
    {
        return $this->postJson('/api/auth/login', compact('username', 'password'));
    }

    /**
     * The auth manager caches the resolved user for the life of the
     * application instance; forget it so each request re-reads its token.
     */
    private function asBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_user_can_log_in_with_username_and_password(): void
    {
        $response = $this->login()
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.username', 'registrar01')
            ->assertJsonPath('user.roles.0.name', 'staff');

        $this->asBearer($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('username', 'registrar01');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->login(password: 'wrong-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_unknown_username_is_rejected(): void
    {
        $this->login(username: 'nobody')
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_login_requires_both_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_logging_in_again_revokes_the_previous_token(): void
    {
        $first = $this->login()->json('token');
        $second = $this->login()->json('token');

        $this->asBearer($first)->getJson('/api/user')->assertUnauthorized();
        $this->asBearer($second)->getJson('/api/user')->assertOk();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $token = $this->login()->json('token');

        $this->asBearer($token)->postJson('/api/auth/logout')->assertOk();

        $this->asBearer($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_can_change_their_password(): void
    {
        $token = $this->login()->json('token');

        $this->asBearer($token)->postJson('/api/auth/change-password', [
            'current_password'      => 'correct-horse',
            'password'              => 'battery-staple',
            'password_confirmation' => 'battery-staple',
        ])->assertOk();

        $this->login(password: 'correct-horse')->assertStatus(422);
        $this->login(password: 'battery-staple')->assertOk();
    }

    public function test_change_password_requires_the_current_password(): void
    {
        $token = $this->login()->json('token');

        $this->asBearer($token)->postJson('/api/auth/change-password', [
            'current_password'      => 'not-my-password',
            'password'              => 'battery-staple',
            'password_confirmation' => 'battery-staple',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_change_password_requires_confirmation(): void
    {
        $token = $this->login()->json('token');

        $this->asBearer($token)->postJson('/api/auth/change-password', [
            'current_password'      => 'correct-horse',
            'password'              => 'battery-staple',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_login(): void
    {
        $this->postJson('/api/auth/change-password', [])->assertUnauthorized();
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'Anonymous',
            'email'                 => 'anon@tmcc.test',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role'                  => 'admin',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'anon@tmcc.test']);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->login(password: 'guess-' . $attempt)->assertStatus(422);
        }

        $this->login()->assertStatus(429);
    }

    // ------------------------------------------------------- inactive (#79)

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $user = \App\Models\User::where('username', 'registrar01')->sole();
        $user->update(['status' => 'inactive']);

        $this->login()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username' => 'This account is inactive. Please contact the administrator.'])
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_inactive_account_with_a_wrong_password_gets_the_usual_message(): void
    {
        \App\Models\User::where('username', 'registrar01')->update(['status' => 'inactive']);

        // The inactive notice only follows a correct password, so it never
        // reveals which usernames exist.
        $this->login(password: 'wrong-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username' => 'The provided credentials are incorrect.']);
    }

    public function test_deactivating_a_signed_in_user_ends_their_session(): void
    {
        $staffToken = $this->login()->assertOk()->json('token');
        $this->makeUser('admin', 'admin01', 'admin-pass');
        $adminToken = $this->login('admin01', 'admin-pass')->assertOk()->json('token');
        $staff = \App\Models\User::where('username', 'registrar01')->sole();

        $this->asBearer($staffToken)->getJson('/api/user')->assertOk();

        $this->asBearer($adminToken)->putJson("/api/admin/users/{$staff->id}", [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'staff', 'status' => 'inactive',
        ])->assertOk();

        $this->asBearer($staffToken)->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(0, $staff->tokens()->count());
        // The administrator's own session is untouched.
        $this->asBearer($adminToken)->getJson('/api/user')->assertOk();
    }

    public function test_reactivated_account_can_log_in_again(): void
    {
        \App\Models\User::where('username', 'registrar01')->update(['status' => 'inactive']);
        $this->login()->assertStatus(422);

        \App\Models\User::where('username', 'registrar01')->update(['status' => 'active']);
        $this->login()->assertOk()->assertJsonStructure(['token']);
    }
}
