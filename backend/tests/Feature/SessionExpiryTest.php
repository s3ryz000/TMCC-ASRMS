<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #86: a login ends after 60 minutes without a request and 12 hours after
 * signing in, whatever the activity. An ended token gets 401 and is deleted.
 * Real bearer tokens throughout (not Sanctum::actingAs), so the token check
 * itself is under test.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->makeUser('staff', 'registrar01', 'correct-horse-7');
    }

    private function login(): string
    {
        return $this->postJson('/api/auth/login', ['username' => 'registrar01', 'password' => 'correct-horse-7'])
            ->assertOk()
            ->json('token');
    }

    /** One request with the token, as a fresh request (no cached user). */
    private function hit(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/user');
    }

    public function test_the_defaults_are_60_minutes_idle_and_12_hours_in_total(): void
    {
        $this->assertSame(60, config('asrms.session.idle_minutes'));
        $this->assertSame(12, config('asrms.session.max_hours'));
        $this->assertSame(720, config('sanctum.expiration'));
    }

    public function test_a_login_token_records_when_it_expires(): void
    {
        $this->freezeSecond();
        $this->login();

        $this->assertTrue(PersonalAccessToken::sole()->expires_at->equalTo(now()->addHours(12)));
    }

    public function test_a_session_idle_for_longer_than_the_window_is_refused_and_deleted(): void
    {
        $token = $this->login();
        $this->hit($token)->assertOk();

        $this->travel(61)->minutes();

        $this->hit($token)->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
        $this->assertSame(0, PersonalAccessToken::count());
        // Coming back does not revive it.
        $this->travelBack();
        $this->hit($token)->assertStatus(401);
    }

    public function test_a_new_login_without_any_request_also_goes_idle(): void
    {
        $token = $this->login();

        $this->travel(61)->minutes();

        $this->hit($token)->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_activity_keeps_the_session_alive(): void
    {
        $token = $this->login();

        // A request every 50 minutes for 5 hours: never idle for 60.
        for ($i = 0; $i < 6; $i++) {
            $this->travel(50)->minutes();
            $this->hit($token)->assertOk();
        }
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_session_ends_after_12_hours_even_while_active(): void
    {
        $token = $this->login();

        for ($i = 0; $i < 14; $i++) { // 11 h 40 min of activity
            $this->travel(50)->minutes();
            $this->hit($token)->assertOk();
        }
        $this->travel(21)->minutes(); // 12 h 1 min after signing in

        $this->hit($token)->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_old_token_from_before_this_change_still_ends_after_12_hours(): void
    {
        $token = $this->login();
        PersonalAccessToken::query()->update(['expires_at' => null]); // as every token was before #86

        for ($i = 0; $i < 14; $i++) {
            $this->travel(50)->minutes();
            $this->hit($token)->assertOk();
        }
        $this->travel(21)->minutes();

        $this->hit($token)->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_signing_in_again_after_expiry_works(): void
    {
        $old = $this->login();
        $this->travel(61)->minutes();
        $this->hit($old)->assertStatus(401);

        $new = $this->login();

        $this->hit($new)->assertOk()->assertJsonPath('username', 'registrar01');
        $this->hit($old)->assertStatus(401);
    }

    public function test_the_idle_window_comes_from_the_environment_setting(): void
    {
        config(['asrms.session.idle_minutes' => 1]);
        $token = $this->login();

        $this->travel(59)->seconds();
        $this->hit($token)->assertOk();
        $this->travel(61)->seconds();

        $this->hit($token)->assertStatus(401);
    }

    public function test_an_expired_session_cannot_log_out_or_change_the_password(): void
    {
        $token = $this->login();
        $this->travel(61)->minutes();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/change-password', [
            'current_password' => 'correct-horse-7', 'password' => 'another-horse-8', 'password_confirmation' => 'another-horse-8',
        ])->assertStatus(401);
    }
}
