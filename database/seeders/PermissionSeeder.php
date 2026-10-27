<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';

        $permissions = [

            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            */

            'dashboard.view',

            /*
            |--------------------------------------------------------------------------
            | Analytics
            |--------------------------------------------------------------------------
            */

            'analytics.view',
            'analytics_sentiment.view',
            'analytics_engagement.view',
            'analytics_topics.view',
            'analytics_trends.view',

            /*
            |--------------------------------------------------------------------------
            | Facebook
            |--------------------------------------------------------------------------
            */

            'facebook_pages.view',
            'facebook_posts.view',
            'facebook_comments.view',
            'facebook_reactions.view',
            'facebook_insights.view',

            /*
            |--------------------------------------------------------------------------
            | Facebook Connections
            |--------------------------------------------------------------------------
            */

            'facebook_connections.view',
            'facebook_connections.manage',

            /*
            |--------------------------------------------------------------------------
            | AI
            |--------------------------------------------------------------------------
            */

            'ai_sentiment.view',
            'ai_topics.view',
            'ai_insights.view',

            /*
            |--------------------------------------------------------------------------
            | AI Settings
            |--------------------------------------------------------------------------
            */

            'ai_settings.view',
            'ai_settings.manage',

            /*
            |--------------------------------------------------------------------------
            | AI Model Management
            |--------------------------------------------------------------------------
            */

            'ai_models.view',
            'ai_models.create',
            'ai_models.update',
            'ai_models.activate',
            'ai_models.delete',

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            'reports.view',
            'reports.create',
            'reports.export',

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            'notifications.view',
            'notifications.read',
            'notifications.create',
            'notifications.update',
            'notifications.delete',
            'notifications.publish',
            'notifications.schedule',
            'notifications.archive',

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            */

            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            /*
            |--------------------------------------------------------------------------
            | Roles
            |--------------------------------------------------------------------------
            */

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',

            /*
            |--------------------------------------------------------------------------
            | Permissions
            |--------------------------------------------------------------------------
            */

            'permissions.view',
            'permissions.manage',

            /*
            |--------------------------------------------------------------------------
            | System Settings
            |--------------------------------------------------------------------------
            */

            'system_settings.view',
            'system_settings.manage',

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        $this->command?->info(
            'Permissions seeded successfully: ' . count($permissions)
        );
    }
}