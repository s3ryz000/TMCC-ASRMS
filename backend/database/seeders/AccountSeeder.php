<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Creates the two starter accounts (admin and staff).
     *
     * Passwords are never stored in the repository. Each one comes from
     * SEED_ADMIN_PASSWORD / SEED_STAFF_PASSWORD in .env, or is generated at
     * random. A password is printed once, only when its account is created.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => env('SEED_ADMIN_PASSWORD') ?: User::generatePassword('admin', 'admin@example.com'),
                'role' => 'admin',
            ],
            [
                'name' => 'Staff',
                'username' => 'staff',
                'email' => 'staff@example.com',
                'password' => env('SEED_STAFF_PASSWORD') ?: User::generatePassword('staff', 'staff@example.com'),
                'role' => 'staff',
            ],
        ];

        echo "Accounts created successfully\n";
        $printed = false;
        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['username' => $userData['username']],
                $userData
            );
            $user->assignRole($userData['role']);

            if ($user->wasRecentlyCreated) {
                echo "Username: {$userData['username']} | Password: {$userData['password']} | Role: {$userData['role']}\n";
                $printed = true;
            } else {
                echo "Username: {$userData['username']} already exists; password unchanged.\n";
            }
        }
        if ($printed) {
            echo "Save these passwords now. They are not shown again.\n";
        }
    }
}
