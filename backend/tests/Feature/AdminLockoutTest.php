<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #81: User Management can't lock the administrators out. Nobody changes
 * their own role or status, and the system always keeps at least one active
 * admin. Refusals are 422 and change nothing.
 */
class AdminLockoutTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->adminA = $this->makeUser('admin', 'admin_a');
        $this->adminA->createToken('session-a');
        Sanctum::actingAs($this->adminA, ['*']);
    }

    private function payload(User $user, array $overrides = []): array
    {
        $user = $user->fresh();

        return array_merge([
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'department' => $user->department ?? 'Registrar', 'status' => $user->status,
        ], $overrides);
    }

    private function update(User $user, array $overrides = [])
    {
        return $this->putJson("/api/admin/users/{$user->id}", $this->payload($user, $overrides));
    }

    // -------------------------------------------------------- own account

    public function test_an_admin_cannot_deactivate_themself(): void
    {
        $this->makeUser('admin', 'admin_b'); // not the last admin: the self rule applies on its own

        $this->update($this->adminA, ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot change your own status or role.');

        $this->assertSame('active', $this->adminA->fresh()->status);
        $this->assertSame(1, $this->adminA->tokens()->count());
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $this->makeUser('admin', 'admin_b');

        $this->update($this->adminA, ['role' => 'staff'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot change your own status or role.');

        $this->assertSame('admin', $this->adminA->fresh()->role);
        $this->assertTrue($this->adminA->fresh()->hasRole('admin'));
    }

    public function test_an_admin_can_still_edit_their_own_details(): void
    {
        $this->update($this->adminA, ['name' => 'Renamed Admin', 'department' => 'IT Office'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Renamed Admin');

        $fresh = $this->adminA->fresh();
        $this->assertSame('IT Office', $fresh->department);
        $this->assertSame('admin', $fresh->role);
        $this->assertSame('active', $fresh->status);
    }

    // -------------------------------------------------- last active admin

    public function test_the_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $adminB = $this->makeUser('admin', 'admin_b');
        $adminB->createToken('session-b');

        // Two active admins: A may deactivate B, which ends B's sessions (#79).
        $this->update($adminB, ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $adminB->fresh()->status);
        $this->assertSame(0, $adminB->tokens()->count());

        // A is now the only active admin. Nobody may deactivate or demote A:
        // not A (own account), and not another admin acting on A.
        $adminC = $this->makeUser('admin', 'admin_c');
        $adminC->forceFill(['status' => 'inactive'])->save();
        Sanctum::actingAs($adminC, ['*']);

        foreach ([['status' => 'inactive'], ['role' => 'staff'], ['role' => 'student']] as $change) {
            $this->update($this->adminA, $change)
                ->assertStatus(422)
                ->assertJsonPath('message', 'At least one active administrator is required.');
        }

        $fresh = $this->adminA->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('admin', $fresh->role);
        $this->assertSame(1, $this->adminA->tokens()->count());
    }

    public function test_reactivating_and_promoting_are_never_blocked(): void
    {
        $staff = $this->makeUser('staff', 'reg01');
        $staff->forceFill(['status' => 'inactive'])->save();

        $this->update($staff, ['status' => 'active', 'role' => 'admin'])->assertOk();

        $this->assertSame('admin', $staff->fresh()->role);
        $this->assertSame('active', $staff->fresh()->status);
    }

    // ------------------------------------------------------------ delete

    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $adminB = $this->makeUser('admin', 'admin_b');
        $adminB->forceFill(['status' => 'inactive'])->save();
        Sanctum::actingAs($adminB, ['*']);

        $this->deleteJson("/api/admin/users/{$this->adminA->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one active administrator is required.');

        $this->assertModelExists($this->adminA);
    }

    public function test_deleting_a_staff_user_or_a_second_admin_still_works(): void
    {
        $staff = $this->makeUser('staff', 'reg01');
        $adminB = $this->makeUser('admin', 'admin_b');

        $this->deleteJson("/api/admin/users/{$staff->id}")->assertOk();
        $this->deleteJson("/api/admin/users/{$adminB->id}")->assertOk();

        $this->assertModelMissing($staff);
        $this->assertModelMissing($adminB);
    }
}
