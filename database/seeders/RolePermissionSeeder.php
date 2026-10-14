<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';

        /*
        |--------------------------------------------------------------------------
        | SYSTEM_ADMIN
        |--------------------------------------------------------------------------
        |
        | SYSTEM_ADMIN receives every permission registered for the API guard.
        |
        | This includes:
        |
        | - Dashboard
        | - Analytics
        | - Facebook
        | - AI
        | - Reports
        | - Users
        | - Roles
        | - Permissions
        | - AI Settings
        | - System Settings
        | - Audit Logs
        |
        | Other roles and their permissions are managed through the
        | administration interface.
        |
        */

        $systemAdmin = Role::where([
            'name' => 'SYSTEM_ADMIN',
            'guard_name' => $guard,
        ])->firstOrFail();

        $allPermissions = Permission::where(
            'guard_name',
            $guard
        )->get();

        $systemAdmin->syncPermissions($allPermissions);

        $this->command?->info(
            'SYSTEM_ADMIN permissions seeded successfully: ' .
            $allPermissions->count()
        );
    }
}