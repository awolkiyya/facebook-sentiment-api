<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';

        /*
        |--------------------------------------------------------------------------
        | System Administrator
        |--------------------------------------------------------------------------
        */

        Role::updateOrCreate(
            [
                'name' => 'SYSTEM_ADMIN',
                'guard_name' => $guard,
            ],
            [
                'is_system' => true,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Deprecated Mayor Role
        |--------------------------------------------------------------------------
        */

        Role::query()
            ->where('name', 'MAYOR')
            ->where('guard_name', $guard)
            ->delete();

        $this->command?->info(
            'Roles seeded successfully: SYSTEM_ADMIN'
        );
    }
}