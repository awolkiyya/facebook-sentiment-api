<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        |
        | Permissions must exist before roles are configured.
        |
        */

        $this->call([
            PermissionSeeder::class,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        |
        | Roles must exist before permissions can be assigned to them.
        |
        */

        $this->call([
            RoleSeeder::class,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Role Permissions
        |--------------------------------------------------------------------------
        |
        | Assign permissions to:
        |
        | - SYSTEM_ADMIN
        | - MAYOR
        |
        */

        $this->call([
            RolePermissionSeeder::class,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Initial System Administrator
        |--------------------------------------------------------------------------
        |
        | Creates or updates the initial system administrator and
        | assigns the SYSTEM_ADMIN role.
        |
        */

        $this->call([
            SystemAdminSeeder::class,
        ]);

        $this->call([
            SystemSettingSeeder::class,
        ]);
    }
}