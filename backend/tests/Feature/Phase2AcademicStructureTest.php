<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\Program;
use App\Models\School;
use App\Models\SystemLog;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\Phase2DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Phase 2 foundation (TMCC letter, 27 Oct 2026, 1A–1C): faculty, program
 * coordinator and dean accounts; schools with deans; programs in schools;
 * coordinators; faculty profiles; and the demo-data seeder.
 */
class Phase2AcademicStructureTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Roles::ALL as $role) {
            Role::findOrCreate($role, 'api');
        }
        $this->admin = $this->makeUser(Roles::ADMIN);
    }

    private function school(array $attrs = []): School
    {
        return School::create(array_merge(['code' => 'SHT', 'name' => 'School of Hospitality and Tourism'], $attrs));
    }

    private function lastLog(): string
    {
        return SystemLog::latest('log_id')->first()->action;
    }

    // ------------------------------------------------------------- accounts

    /**
     * @dataProvider academicRoles
     */
    public function test_the_admin_creates_academic_accounts(string $role): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/users', [
            'name' => 'New Person', 'username' => "new.{$role}", 'email' => "new.{$role}@tmcc.test", 'role' => $role,
            'password' => 'Harbor-Lantern-47', 'password_confirmation' => 'Harbor-Lantern-47',
        ])->assertCreated()->assertJsonPath('user.role', $role);

        $this->assertTrue(User::where('username', "new.{$role}")->first()->hasRole($role));
    }

    public static function academicRoles(): array
    {
        return array_combine(Roles::ACADEMIC, array_map(fn ($r) => [$r], Roles::ACADEMIC));
    }

    public function test_academic_accounts_can_read_their_own_profile_and_the_current_term(): void
    {
        $dean = $this->makeUser(Roles::DEAN, 'dean01');
        $this->school(['dean_user_id' => $dean->id]);
        Sanctum::actingAs($dean, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('role', Roles::DEAN);
        $this->getJson('/api/settings/current')->assertOk();
        $this->getJson('/api/academic/me')
            ->assertOk()
            ->assertJsonPath('role_label', 'Dean')
            ->assertJsonPath('dean_of.code', 'SHT');
    }

    public function test_only_academic_roles_open_the_academic_portal(): void
    {
        foreach ([Roles::ADMIN, Roles::STAFF, Roles::STUDENT] as $role) {
            Sanctum::actingAs($this->makeUser($role, "x.{$role}"), ['*']);
            $this->getJson('/api/academic/me')->assertForbidden();
        }
    }

    /**
     * Phase 2 adds roles but no access yet: faculty, coordinators and deans are
     * refused every registrar, admin and student endpoint.
     */
    public function test_academic_roles_are_refused_every_staff_admin_and_student_endpoint(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => preg_match('#^api/(staff|admin|student)/#', $r->uri()))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [$m, $r->uri()]));

        $this->assertGreaterThan(50, $routes->count());

        foreach (Roles::ACADEMIC as $role) {
            Sanctum::actingAs($this->makeUser($role, "probe.{$role}"), ['*']);
            foreach ($routes as [$method, $uri]) {
                $url = '/' . preg_replace('/\{[^}]+\}/', '1', $uri);
                $status = $this->json($method, $url)->status();
                $this->assertSame(403, $status, "{$role} got {$status} on {$method} {$url}");
            }
        }
    }

    // -------------------------------------------------------------- schools

    public function test_the_admin_creates_and_lists_schools(): void
    {
        $dean = $this->makeUser(Roles::DEAN, 'dean01');
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/schools', ['code' => 'SHT', 'name' => 'School of Hospitality and Tourism', 'dean_user_id' => $dean->id])
            ->assertCreated()
            ->assertJsonPath('school.dean.username', 'dean01');
        $this->assertSame('School created: SHT — School of Hospitality and Tourism', $this->lastLog());

        $this->getJson('/api/admin/schools')
            ->assertOk()
            ->assertJsonPath('schools.0.code', 'SHT')
            ->assertJsonPath('people.0.username', 'dean01');
    }

    public function test_school_codes_are_unique(): void
    {
        $this->school();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/schools', ['code' => 'SHT', 'name' => 'Another'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_dean_must_be_an_active_dean_account(): void
    {
        $staff = $this->makeUser(Roles::STAFF, 'reg01');
        $inactive = $this->makeUser(Roles::DEAN, 'dean.old');
        $inactive->forceFill(['status' => 'inactive'])->save();
        Sanctum::actingAs($this->admin, ['*']);

        foreach ([$staff->id, $inactive->id] as $id) {
            $this->postJson('/api/admin/schools', ['code' => "S{$id}", 'name' => 'X', 'dean_user_id' => $id])
                ->assertStatus(422)->assertJsonValidationErrors('dean_user_id');
        }
    }

    public function test_a_dean_leads_only_one_school(): void
    {
        $dean = $this->makeUser(Roles::DEAN, 'dean01');
        $this->school(['dean_user_id' => $dean->id]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/schools', ['code' => 'SBE', 'name' => 'Business', 'dean_user_id' => $dean->id])
            ->assertStatus(422)->assertJsonValidationErrors('dean_user_id');
    }

    public function test_the_admin_updates_a_school_and_its_dean(): void
    {
        $school = $this->school();
        $dean = $this->makeUser(Roles::DEAN, 'dean01');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/schools/{$school->id}", ['code' => 'SHT', 'name' => 'Hospitality', 'dean_user_id' => $dean->id])
            ->assertOk()->assertJsonPath('school.dean.username', 'dean01');
        $this->assertSame('School updated: SHT — Hospitality; dean dean01', $this->lastLog());
    }

    public function test_schools_are_archived_never_deleted(): void
    {
        $school = $this->school();
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/schools/{$school->id}/archive")->assertOk();
        $this->assertNotNull($school->fresh()->archived_at);
        $this->patchJson("/api/admin/schools/{$school->id}/unarchive")->assertOk();
        $this->assertNull($school->fresh()->archived_at);

        $this->deleteJson("/api/admin/schools/{$school->id}")->assertStatus(405);
        $this->assertModelExists($school);
    }

    // ------------------------------------------------ programs and coordinators

    public function test_a_program_is_put_in_a_school_and_taken_out(): void
    {
        $school = $this->school();
        $program = $this->makeProgram('BSHM', 'BS Hospitality Management');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/programs/{$program->id}/school", ['school_id' => $school->id])
            ->assertOk()->assertJsonPath('program.school.code', 'SHT');
        $this->assertSame('Program BSHM assigned to school SHT', $this->lastLog());

        $this->putJson("/api/admin/programs/{$program->id}/school", ['school_id' => null])->assertOk();
        $this->assertNull($program->fresh()->school_id);
    }

    public function test_an_archived_school_takes_no_programs(): void
    {
        $school = $this->school();
        $school->forceFill(['archived_at' => now()])->save();
        $program = $this->makeProgram();
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/programs/{$program->id}/school", ['school_id' => $school->id])
            ->assertStatus(422)->assertJsonValidationErrors('school_id');
    }

    public function test_only_coordinator_accounts_coordinate_a_program(): void
    {
        $program = $this->makeProgram();
        $coordinator = $this->makeUser(Roles::COORDINATOR, 'coord01');
        $faculty = $this->makeUser(Roles::FACULTY, 'fac01');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/programs/{$program->id}/coordinators", ['user_ids' => [$faculty->id]])
            ->assertStatus(422)->assertJsonValidationErrors('user_ids.0');

        $this->putJson("/api/admin/programs/{$program->id}/coordinators", ['user_ids' => [$coordinator->id]])
            ->assertOk()->assertJsonPath('program.coordinators.0.username', 'coord01');
        $this->assertSame('Program BSIT coordinators: coord01', $this->lastLog());

        Sanctum::actingAs($coordinator, ['*']);
        $this->getJson('/api/academic/me')->assertOk()->assertJsonPath('coordinates.0.code', 'BSIT');
    }

    public function test_a_program_may_have_no_coordinator(): void
    {
        $program = $this->makeProgram();
        $coordinator = $this->makeUser(Roles::COORDINATOR, 'coord01');
        $program->coordinators()->attach($coordinator->id);
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/programs/{$program->id}/coordinators", ['user_ids' => []])->assertOk();
        $this->assertSame(0, $program->coordinators()->count());
    }

    // --------------------------------------------------------- faculty profiles

    public function test_the_admin_sets_a_faculty_members_school_and_employee_number(): void
    {
        $school = $this->school();
        $faculty = $this->makeUser(Roles::FACULTY, 'fac01');
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/faculty/{$faculty->id}/profile", ['school_id' => $school->id, 'employee_no' => 'E-0001'])
            ->assertOk()->assertJsonPath('profile.school.code', 'SHT');

        Sanctum::actingAs($faculty, ['*']);
        $this->getJson('/api/academic/me')->assertOk()
            ->assertJsonPath('faculty.employee_no', 'E-0001')
            ->assertJsonPath('faculty.school.code', 'SHT');
    }

    public function test_faculty_profiles_are_for_faculty_only_and_employee_numbers_are_unique(): void
    {
        $staff = $this->makeUser(Roles::STAFF, 'reg01');
        $a = $this->makeUser(Roles::FACULTY, 'fac01');
        $b = $this->makeUser(Roles::FACULTY, 'fac02');
        FacultyProfile::create(['user_id' => $a->id, 'employee_no' => 'E-0001']);
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/admin/faculty/{$staff->id}/profile", ['employee_no' => 'E-9'])->assertNotFound();
        $this->putJson("/api/admin/faculty/{$b->id}/profile", ['employee_no' => 'E-0001'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_no');
    }

    // ----------------------------------------------------------- access

    public function test_only_the_admin_manages_the_academic_structure(): void
    {
        $school = $this->school();
        $program = $this->makeProgram();

        foreach ([Roles::STAFF, Roles::STUDENT, ...Roles::ACADEMIC] as $role) {
            Sanctum::actingAs($this->makeUser($role, "who.{$role}"), ['*']);
            $this->getJson('/api/admin/schools')->assertForbidden();
            $this->postJson('/api/admin/schools', ['code' => 'X', 'name' => 'X'])->assertForbidden();
            $this->putJson("/api/admin/schools/{$school->id}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
            $this->putJson("/api/admin/programs/{$program->id}/school", ['school_id' => null])->assertForbidden();
            $this->putJson("/api/admin/programs/{$program->id}/coordinators", ['user_ids' => []])->assertForbidden();
        }
    }

    // ----------------------------------------------------------- demo seeder

    public function test_the_demo_seeder_builds_fictional_schools_and_people_and_can_run_twice(): void
    {
        $this->makeProgram('BSHM', 'BS Hospitality Management');
        $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeProgram('BSE', 'BS Entrepreneurship');

        $this->seed(Phase2DemoSeeder::class);
        $users = User::count();
        $this->seed(Phase2DemoSeeder::class);

        $this->assertSame($users, User::count());
        $this->assertSame(2, School::count());
        $this->assertSame('DEMO-SHT', Program::where('code', 'BSHM')->first()->school->code);
        $this->assertSame('dean.sbe', School::where('code', 'DEMO-SBE')->first()->dean->username);
        $this->assertSame(['coord.bshm'], Program::where('code', 'BSHM')->first()->coordinators->pluck('username')->all());
        $this->assertSame(3, FacultyProfile::count());
        $this->assertSame(0, User::where('role', '!=', Roles::ADMIN)->where('email', 'not like', '%@demo.tmcc.test')->count());
    }

    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        (new Phase2DemoSeeder())->run();
    }
}
