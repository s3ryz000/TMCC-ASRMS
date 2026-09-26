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

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->login(password: 'guess-' . $attempt)->assertStatus(422);
        }

        $this->login()->assertStatus(429);
    }
}
