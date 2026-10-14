<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | System Administrator
        |--------------------------------------------------------------------------
        */

        $admin = User::updateOrCreate(
            [
                'email' => 'admin@example.com',
            ],
            [
                'name' => 'System Administrator',

                /*
                |--------------------------------------------------------------------------
                | Password
                |--------------------------------------------------------------------------
                |
                | The password should come from the environment rather than being
                | committed to source control.
                |
                */

                'password' => Hash::make(
                    env(
                        'SYSTEM_ADMIN_PASSWORD',
                        'ChangeThisPasswordImmediately!'
                    )
                ),

                'is_active' => true,

                'email_verified_at' => now(),

                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'locked_until' => null,
                'lockout_count' => 0,
                'last_login_at' => null,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Assign Role
        |--------------------------------------------------------------------------
        */

        $admin->syncRoles([
            'SYSTEM_ADMIN',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Seeder Output
        |--------------------------------------------------------------------------
        */

        $this->command?->info(
            'System administrator seeded successfully.'
        );

        $this->command?->info(
            'Email: admin@example.com'
        );

        $this->command?->warn(
            'Change the default administrator password immediately.'
        );
    }
}
