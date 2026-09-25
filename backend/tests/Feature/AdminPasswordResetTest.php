<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Administrative password recovery (§3.9.1).
 *
 * The scope puts this system on an isolated LAN with no mail server, so the
 * usual "forgot password" e-mail loop is impossible. Recovery is therefore the
 * administrator resetting the credential in person. Before this, the update
 * endpoint silently ignored a password field, which left a user who forgot
 * their password with no route back into the system at all.
 */
class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['staff', 'admin', 'student'] as $role) {
            Role::findOrCreate($role, 'api');
        }

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@tmcc.test', 'username' => 'admin01',
            'role' => 'admin', 'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole('admin');

        $this->target = User::create([
            'name' => 'Registrar', 'email' => 'reg@tmcc.test', 'username' => 'reg01',
            'role' => 'staff', 'password' => bcrypt('original-password'),
        ]);
        $this->target->assignRole('staff');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'   => $this->target->name,
            'email'  => $this->target->email,
            'role'   => 'staff',
            'status' => 'active',
        ], $overrides);
    }

    public function test_admin_can_reset_a_users_password(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]))->assertOk();

        $this->assertTrue(
            Hash::check('new-password-123', $this->target->fresh()->password),
            'The new password should have been hashed and stored.'
        );
    }

    public function test_password_reset_is_written_to_the_audit_log(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]))->assertOk();

        $this->assertNotNull(
            SystemLog::where('action', 'like', '%Password reset%')->first(),
            'A password reset must leave an audit trail (§3.9.3).'
        );
    }

    public function test_updating_other_fields_leaves_the_password_untouched(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'name' => 'Renamed Registrar',
        ]))->assertOk();

        $fresh = $this->target->fresh();

        $this->assertSame('Renamed Registrar', $fresh->name);
        $this->assertTrue(
            Hash::check('original-password', $fresh->password),
            'Omitting the password field must not disturb the stored credential.'
        );
    }

    public function test_blank_password_is_ignored_rather_than_stored(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => '',
            'password_confirmation' => '',
        ]))->assertOk();

        $this->assertTrue(
            Hash::check('original-password', $this->target->fresh()->password),
            'An empty password field means "leave it alone", not "set it to empty".'
        );
    }

    public function test_short_password_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => 'new-password-123',
            'password_confirmation' => 'different-password',
        ]))->assertStatus(422);
    }

    public function test_non_admin_cannot_reset_passwords(): void
    {
        Sanctum::actingAs($this->target, ['*']); // staff

        $this->putJson('/api/admin/users/' . $this->target->id, $this->payload([
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]))->assertStatus(403);

        $this->assertTrue(Hash::check('original-password', $this->target->fresh()->password));
    }
}
