<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #95: sign-ins, sign-outs, session expiry, account changes and settings
 * changes are written to system_logs, with the actor and what changed and
 * never a password. The log itself can't be edited or deleted.
 */
class AuditEventsLogTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const PASSWORD = 'Correct-Horse-7';

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff', 'uat.staff', self::PASSWORD);
        $this->admin = $this->makeUser('admin', 'uat.admin', self::PASSWORD);
    }

    private function actions(): array
    {
        return SystemLog::orderBy('log_id')->pluck('action')->all();
    }

    private function login(string $username, string $password)
    {
        return $this->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    /** A fresh request with a bearer token (no cached user). */
    private function hit(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/user');
    }

    protected function tearDown(): void
    {
        // Whatever a test did, no log row may hold a password.
        foreach (SystemLog::pluck('action') as $action) {
            foreach ([self::PASSWORD, 'Wrong-Pass-99', 'New-Strong-Pass-42'] as $secret) {
                $this->assertStringNotContainsString($secret, $action);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------- sign-in

    public function test_a_successful_login_is_logged_with_the_user_and_role(): void
    {
        $this->login('uat.staff', self::PASSWORD)->assertOk();

        $this->assertDatabaseHas('system_logs', ['action' => 'Login: uat.staff', 'user_id' => $this->staff->id, 'role' => 'staff']);
    }

    public function test_a_failed_login_logs_only_the_username_typed(): void
    {
        $this->login('adm1n', 'Wrong-Pass-99')->assertStatus(422);
        $this->login('uat.staff', 'Wrong-Pass-99')->assertStatus(422);

        $this->assertSame([
            "Failed login: unknown user 'adm1n'",
            'Failed login: uat.staff — wrong password',
        ], $this->actions());
        $this->assertSame(['guest'], SystemLog::distinct()->pluck('role')->all());
        $this->assertSame(0, SystemLog::whereNotNull('user_id')->count());
    }

    public function test_a_login_to_an_inactive_account_is_logged_as_failed(): void
    {
        $this->staff->update(['status' => 'inactive']);

        $this->login('uat.staff', self::PASSWORD)->assertStatus(422);

        $this->assertSame(['Failed login: uat.staff — account inactive'], $this->actions());
    }

    public function test_a_long_or_odd_username_is_stored_safely(): void
    {
        $this->login(str_repeat('x', 300) . "\n\tadmin", 'Wrong-Pass-99')->assertStatus(422);

        $action = SystemLog::sole()->action;
        $this->assertLessThanOrEqual(255, mb_strlen($action));
        $this->assertStringNotContainsString("\n", $action);
    }

    public function test_logins_stay_rate_limited(): void
    {
        foreach (range(1, 6) as $i) {
            $this->login('adm1n', 'Wrong-Pass-99')->assertStatus(422);
        }
        $this->login('adm1n', 'Wrong-Pass-99')->assertStatus(429);

        $this->assertSame(6, SystemLog::count());
    }

    public function test_a_logout_is_logged(): void
    {
        $token = $this->login('uat.staff', self::PASSWORD)->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(['Login: uat.staff', 'Logout: uat.staff'], $this->actions());
        $this->assertSame($this->staff->id, SystemLog::latest('log_id')->value('user_id'));
    }

    public function test_an_idle_session_expiry_is_logged_once(): void
    {
        $token = $this->login('uat.staff', self::PASSWORD)->json('token');
        $this->travel(61)->minutes();

        $this->hit($token)->assertStatus(401);
        $this->hit($token)->assertStatus(401);

        $this->assertSame(['Login: uat.staff', 'Session expired: uat.staff (idle over 60 min)'], $this->actions());
        $this->assertSame($this->staff->id, SystemLog::latest('log_id')->value('user_id'));
    }

    public function test_reaching_the_session_time_limit_is_logged(): void
    {
        $token = $this->login('uat.staff', self::PASSWORD)->json('token');
        // Active every 50 minutes (never idle) for 11 h 40 min, then past 12 hours.
        foreach (range(1, 14) as $i) {
            $this->travel(50)->minutes();
            $this->hit($token)->assertOk();
        }
        $this->travel(21)->minutes();
        $this->hit($token)->assertStatus(401);
        $this->hit($token)->assertStatus(401);

        $this->assertContains('Session expired: uat.staff (12-hour limit)', $this->actions());
        $this->assertSame(1, SystemLog::where('action', 'like', 'Session expired%')->count());
    }

    // --------------------------------------------------------- user changes

    public function test_creating_a_user_is_logged(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/users', [
            'name' => 'New Registrar', 'username' => 'new.registrar', 'email' => 'new.registrar@tmcc.test',
            'password' => 'New-Strong-Pass-42', 'password_confirmation' => 'New-Strong-Pass-42', 'role' => 'staff',
        ])->assertCreated();

        $this->assertDatabaseHas('system_logs', ['action' => 'User created: new.registrar (staff)', 'user_id' => $this->admin->id, 'role' => 'admin']);
    }

    private function updateStaff(array $changes)
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->putJson("/api/admin/users/{$this->staff->id}", $changes + [
            'name' => $this->staff->name, 'email' => $this->staff->email, 'role' => 'staff', 'status' => 'active',
        ]);
    }

    public function test_editing_a_user_logs_which_fields_changed(): void
    {
        $this->updateStaff(['name' => 'Renamed Staff', 'department' => 'Registrar'])->assertOk();

        $this->assertSame(['User updated: uat.staff — changed name, department'], $this->actions());
        $this->assertSame($this->admin->id, SystemLog::sole()->user_id);
    }

    public function test_a_role_change_is_logged_old_to_new(): void
    {
        $this->updateStaff(['role' => 'admin'])->assertOk();

        $this->assertSame(['User updated: uat.staff — role staff → admin'], $this->actions());
    }

    public function test_deactivating_and_activating_a_user_are_logged(): void
    {
        $this->updateStaff(['status' => 'inactive'])->assertOk();
        $this->updateStaff(['status' => 'active'])->assertOk();

        $this->assertSame(['User deactivated: uat.staff', 'User activated: uat.staff'], $this->actions());
    }

    public function test_saving_a_user_unchanged_logs_nothing(): void
    {
        $this->updateStaff([])->assertOk();

        $this->assertSame([], $this->actions());
    }

    public function test_a_password_reset_is_logged_without_the_password(): void
    {
        $this->updateStaff(['password' => 'New-Strong-Pass-42', 'password_confirmation' => 'New-Strong-Pass-42'])->assertOk();

        $this->assertCount(1, $this->actions());
        $this->assertStringStartsWith('Password reset for user uat.staff', $this->actions()[0]);
        // tearDown checks no row holds the password.
    }

    public function test_deleting_a_user_is_logged(): void
    {
        $spare = $this->makeUser('staff', 'spare.staff');
        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/users/{$spare->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $spare->id]);
        $this->assertSame(['User deleted: spare.staff (staff)'], $this->actions());
    }

    public function test_a_user_with_logged_activity_is_kept_and_their_log_rows_too(): void
    {
        $this->login('uat.staff', self::PASSWORD)->assertOk();
        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/users/{$this->staff->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account has activity in the system log and cannot be deleted. Deactivate it instead.');

        $this->assertDatabaseHas('users', ['id' => $this->staff->id]);
        $this->assertSame(['Login: uat.staff'], $this->actions());
    }

    // ------------------------------------------------------------- settings

    public function test_a_settings_change_is_logged_old_to_new(): void
    {
        SystemSetting::setValue('academic_year', '2025-2026');
        SystemSetting::setValue('semester', '1st Semester');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/settings', [
            'academic_year' => '2026-2027', 'semester' => '1st Semester', 'email_notifications_enabled' => true,
        ])->assertOk();

        $this->assertSame(
            ['Settings changed: academic year 2025-2026 → 2026-2027; email notifications enabled off → on'],
            $this->actions(),
        );
        $this->assertSame($this->admin->id, SystemLog::sole()->user_id);
    }

    public function test_saving_settings_unchanged_logs_nothing(): void
    {
        SystemSetting::setValue('academic_year', '2025-2026');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/settings', ['academic_year' => '2025-2026'])->assertOk();

        $this->assertSame([], $this->actions());
    }

    // ---------------------------------------------------------- append-only

    public function test_no_endpoint_edits_or_deletes_log_rows(): void
    {
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'log') && ! str_contains($route->uri(), 'login') && ! str_contains($route->uri(), 'logout'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
            ->map(fn ($route) => implode('|', $route->methods()) . ' ' . $route->uri())
            ->values()->all();

        $this->assertSame([], $writes);
    }
}
