<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Enforces the capstone scope boundary between the two privileged roles.
 *
 * §1.4 and §3.9.3 both state that administrators are "limited only to system
 * administration functions ... and are not permitted to perform CRUD
 * operations on student academic records". Every student write used to accept
 * role:staff,admin, so an admin account could create, edit and delete academic
 * records outright. These tests pin the boundary down in both directions:
 * admins keep the read access their oversight and reporting duties need
 * (Fig. 6.3), and lose every write.
 */
class AdminRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $student;
    private Student $record;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['staff', 'admin', 'student'] as $role) {
            Role::findOrCreate($role, 'api');
        }

        $this->admin = $this->makeUser('admin');
        $this->staff = $this->makeUser('staff');
        $this->student = $this->makeUser('student');

        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology']);

        $this->record = Student::create([
            'program_id'      => $program->id,
            'student_number'  => '2026-0042',
            'first_name'      => 'Maria',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2005-05-05',
            'email'           => 'maria@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@tmcc.test',
            'username' => $role . '01',
            'role'     => $role,
            'password' => bcrypt('secret'),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function studentUrl(string $suffix = ''): string
    {
        return '/api/staff/students/' . $this->record->student_id . $suffix;
    }

    // ------------------------------------------------ admin keeps read access

    public function test_admin_can_list_students(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/staff/students')->assertOk();
    }

    public function test_admin_can_view_a_student_record(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson($this->studentUrl())->assertOk();
    }

    public function test_admin_can_read_pending_profile_updates(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/staff/pending-profile-updates')->assertOk();
    }

    public function test_admin_can_still_read_reports(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/staff/reports/summary')->assertOk();
    }

    // -------------------------------------------------- admin loses every write

    /**
     * @dataProvider forbiddenAdminWrites
     */
    public function test_admin_cannot_write_student_records(string $method, string $suffix, array $payload = []): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->json($method, $this->studentUrl($suffix), $payload)
            ->assertStatus(403);
    }

    public static function forbiddenAdminWrites(): array
    {
        return [
            'update student'    => ['PUT', '', ['first_name' => 'Edited']],
            'archive student'   => ['POST', '/archive', []],
            'change program'    => ['PATCH', '/program', ['new_program_id' => 1, 'reason' => 'x']],
            'add enrollment'    => ['POST', '/enrollments', ['subject_ids' => [1]]],
            'update enrollment' => ['PUT', '/enrollments/1', []],
            'delete enrollment' => ['DELETE', '/enrollments/1', []],
            'add grade'         => ['POST', '/grades', []],
            'update grade'      => ['PUT', '/grades/1', []],
            'delete grade'      => ['DELETE', '/grades/1', []],
            'bulk grades'       => ['PUT', '/grades/bulk-update', []],
            'add next term'     => ['POST', '/enrollments/add-next-term', []],
        ];
    }

    public function test_admin_cannot_create_a_student(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/staff/students', [])->assertStatus(403);
    }

    public function test_admin_cannot_approve_a_profile_update(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson('/api/staff/pending-profile-updates/1/approve', [])
            ->assertStatus(403);
    }

    public function test_admin_cannot_reject_a_profile_update(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson('/api/staff/pending-profile-updates/1/reject', ['reason' => 'x'])
            ->assertStatus(403);
    }

    // ------------------------------------------------- registrar keeps its job

    public function test_staff_can_still_read_students(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson('/api/staff/students')->assertOk();
    }

    public function test_staff_writes_are_not_blocked_by_role(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        // The payload is intentionally thin, so this may fail validation — the
        // point is that it is never rejected on role grounds.
        $response = $this->postJson($this->studentUrl('/enrollments'), []);

        $this->assertNotSame(403, $response->status(), 'Registrar staff must retain write access.');
    }

    // --------------------------------------------------------- students locked out

    public function test_student_role_cannot_reach_registrar_endpoints(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson('/api/staff/students')->assertStatus(403);
        $this->putJson($this->studentUrl(), ['first_name' => 'Edited'])->assertStatus(403);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/staff/students')->assertStatus(401);
        $this->postJson('/api/staff/students', [])->assertStatus(401);
    }
}
