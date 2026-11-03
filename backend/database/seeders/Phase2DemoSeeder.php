<?php

namespace Database\Seeders;

use App\Models\FacultyProfile;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 2 demo data: fictional schools, deans, a program coordinator and
 * faculty, for development, tests and the staging site only (TMCC letter,
 * item 10: test with dummy records before any authorized production use).
 *
 * Not part of DatabaseSeeder. Run it on purpose:
 *   php artisan db:seed --class=Phase2DemoSeeder
 * It refuses to run in production. Re-running it updates the same rows.
 *
 * All accounts get the password in PHASE2_DEMO_PASSWORD, or a random one that
 * is printed once. They are fictional people at @demo.tmcc.test addresses.
 */
class Phase2DemoSeeder extends Seeder
{
    public const SCHOOLS = [
        'DEMO-SHT' => ['name' => 'School of Hospitality and Tourism (demo)', 'programs' => ['BSHM', 'BSTM']],
        'DEMO-SBE' => ['name' => 'School of Business and Entrepreneurship (demo)', 'programs' => ['BSE']],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Phase2DemoSeeder creates demo accounts and must not run in production.');
        }

        $this->call(RoleSeeder::class);

        $password = env('PHASE2_DEMO_PASSWORD') ?: Str::password(14, symbols: false);

        $deans = [
            'DEMO-SHT' => $this->account('dean.sht', 'Demo Dean, Hospitality', Roles::DEAN, $password),
            'DEMO-SBE' => $this->account('dean.sbe', 'Demo Dean, Business', Roles::DEAN, $password),
        ];

        foreach (self::SCHOOLS as $code => $def) {
            $school = School::updateOrCreate(['code' => $code], ['name' => $def['name'], 'dean_user_id' => $deans[$code]->id]);
            Program::whereIn('code', $def['programs'])->update(['school_id' => $school->id]);
        }

        $coordinator = $this->account('coord.bshm', 'Demo Coordinator, BSHM', Roles::COORDINATOR, $password);
        if ($bshm = Program::where('code', 'BSHM')->first()) {
            $bshm->coordinators()->syncWithoutDetaching([$coordinator->id]);
        }

        $faculty = [
            ['faculty.demo1', 'Demo Faculty One', 'DEMO-SHT', 'DEMO-0001'],
            ['faculty.demo2', 'Demo Faculty Two', 'DEMO-SHT', 'DEMO-0002'],
            ['faculty.demo3', 'Demo Faculty Three', 'DEMO-SBE', 'DEMO-0003'],
        ];
        foreach ($faculty as [$username, $name, $schoolCode, $employeeNo]) {
            $user = $this->account($username, $name, Roles::FACULTY, $password);
            FacultyProfile::updateOrCreate(
                ['user_id' => $user->id],
                ['school_id' => School::where('code', $schoolCode)->value('id'), 'employee_no' => $employeeNo],
            );
        }

        if (! env('PHASE2_DEMO_PASSWORD') && $this->command) {
            $this->command->warn("Phase 2 demo accounts (dean.sht, dean.sbe, coord.bshm, faculty.demo1-3) password: {$password}");
            $this->command->warn('It is shown only now. Never use demo accounts on the live site.');
        }
    }

    private function account(string $username, string $name, string $role, string $password): User
    {
        $user = User::updateOrCreate(
            ['username' => $username],
            ['name' => $name, 'email' => "{$username}@demo.tmcc.test", 'role' => $role, 'status' => 'active', 'password' => $password],
        );
        $user->syncRoles([$role]);

        return $user;
    }
}
