<?php

namespace App\Modules\Administration\Services;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PermissionService
{
    /**
     * Get all permissions available for the API guard.
     */
    public function list(): Collection
    {
        Log::info('PermissionService::list() started.', [
            'guard_name' => 'api',
        ]);

        $permissions = Permission::query()
            ->where('guard_name', 'api')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'guard_name',
            ]);

        Log::info('PermissionService permissions retrieved.', [
            'guard_name' => 'api',
            'count' => $permissions->count(),
            'permission_names' => $permissions->pluck('name')->values()->all(),
        ]);

        $permissions = $permissions->map(
            function (Permission $permission): Permission {
                Log::debug(
                    'PermissionService transforming permission.',
                    [
                        'permission_id' => (string) $permission->id,
                        'permission_name' => $permission->name,
                    ]
                );

                $parts = explode('.', $permission->name, 2);

                $resource = $parts[0] ?? $permission->name;
                $action = $parts[1] ?? 'view';

                $group = $this->resolveGroup($resource);

                $permission->setAttribute(
                    'group',
                    $group
                );

                $permission->setAttribute(
                    'group_label',
                    $this->resolveGroupLabel($resource)
                );

                $permission->setAttribute(
                    'group_description',
                    $this->resolveGroupDescription($resource)
                );

                $permission->setAttribute(
                    'resource',
                    $resource
                );

                $permission->setAttribute(
                    'label',
                    $this->resolveResourceLabel($resource)
                );

                $permission->setAttribute(
                    'description',
                    $this->resolvePermissionDescription(
                        $resource,
                        $action
                    )
                );

                $permission->setAttribute(
                    'action',
                    $action
                );

                $permission->setAttribute(
                    'action_label',
                    $this->resolveActionLabel($action)
                );

                Log::debug(
                    'PermissionService permission transformed.',
                    [
                        'permission_name' => $permission->name,
                        'group' => $group,
                        'resource' => $resource,
                        'action' => $action,
                        'action_label' => $permission->getAttribute(
                            'action_label'
                        ),
                    ]
                );

                return $permission;
            }
        );

        Log::info('PermissionService::list() completed.', [
            'count' => $permissions->count(),
        ]);

        return $permissions;
    }

    /**
     * Resolve the logical permission group.
     */
    protected function resolveGroup(string $resource): string
    {
        return match (true) {
            str_starts_with($resource, 'analytics') => 'analytics',
            str_starts_with($resource, 'facebook_') => 'facebook',
            str_starts_with($resource, 'ai_') => 'ai',
            str_starts_with($resource, 'reports') => 'reports',
            str_starts_with($resource, 'users') => 'administration',
            str_starts_with($resource, 'roles') => 'administration',
            str_starts_with($resource, 'permissions') => 'administration',
            str_starts_with($resource, 'system_settings') => 'system',
            str_starts_with($resource, 'audit') => 'system',
            default => 'other',
        };
    }

    /**
     * Resolve the human-readable group label.
     */
    protected function resolveGroupLabel(string $resource): string
    {
        return match ($this->resolveGroup($resource)) {
            'analytics' => 'Analytics',
            'facebook' => 'Facebook',
            'ai' => 'AI',
            'reports' => 'Reports',
            'administration' => 'Administration',
            'system' => 'System',
            default => 'Other',
        };
    }

    /**
     * Resolve the group description.
     */
    protected function resolveGroupDescription(string $resource): string
    {
        return match ($this->resolveGroup($resource)) {
            'analytics' =>
                'Analytics dashboards, sentiment, engagement, topics, and trends.',

            'facebook' =>
                'Facebook pages, content, engagement, connections, and insights.',

            'ai' =>
                'AI-powered sentiment analysis, topics, insights, and configuration.',

            'reports' =>
                'Reports, report generation, and report exports.',

            'administration' =>
                'Users, roles, and permission administration.',

            'system' =>
                'System configuration and audit information.',

            default =>
                'Other application permissions.',
        };
    }

    /**
     * Resolve the human-readable resource label.
     */
    protected function resolveResourceLabel(string $resource): string
    {
        return match ($resource) {
            'dashboard' => 'Dashboard',

            'analytics' => 'Analytics',
            'analytics_sentiment' => 'Sentiment Analytics',
            'analytics_engagement' => 'Engagement Analytics',
            'analytics_topics' => 'Topic Analytics',
            'analytics_trends' => 'Trend Analytics',

            'facebook_pages' => 'Facebook Pages',
            'facebook_posts' => 'Facebook Posts',
            'facebook_comments' => 'Facebook Comments',
            'facebook_reactions' => 'Facebook Reactions',
            'facebook_insights' => 'Facebook Insights',
            'facebook_connections' => 'Facebook Connections',

            'ai_sentiment' => 'AI Sentiment',
            'ai_topics' => 'AI Topics',
            'ai_insights' => 'AI Insights',
            'ai_settings' => 'AI Settings',

            'reports' => 'Reports',

            'users' => 'Users',
            'roles' => 'Roles',
            'permissions' => 'Permissions',

            'system_settings' => 'System Settings',
            'audit' => 'Audit',

            default => Str::headline($resource),
        };
    }

    /**
     * Resolve the permission description.
     */
    protected function resolvePermissionDescription(
        string $resource,
        string $action
    ): string {
        return match ($resource) {
            'dashboard' =>
                'Access the application dashboard.',

            'analytics' =>
                'Access general analytics.',

            'analytics_sentiment' =>
                'Access sentiment analytics.',

            'analytics_engagement' =>
                'Access engagement analytics.',

            'analytics_topics' =>
                'Access topic analytics.',

            'analytics_trends' =>
                'Access trend analytics.',

            'facebook_pages' =>
                'Access Facebook page information.',

            'facebook_posts' =>
                'Access Facebook posts.',

            'facebook_comments' =>
                'Access Facebook comments.',

            'facebook_reactions' =>
                'Access Facebook reactions.',

            'facebook_insights' =>
                'Access Facebook page insights.',

            'facebook_connections' => match ($action) {
                'view' =>
                    'View Facebook page connections.',

                'manage' =>
                    'Connect, disconnect, and manage Facebook page connections.',

                default =>
                    'Manage Facebook page connections.',
            },

            'ai_sentiment' =>
                'Access AI-powered sentiment analysis.',

            'ai_topics' =>
                'Access AI-powered topic analysis.',

            'ai_insights' =>
                'Access AI-generated insights.',

            'ai_settings' => match ($action) {
                'view' =>
                    'View AI configuration.',

                'manage' =>
                    'Manage AI configuration.',

                default =>
                    'Manage AI configuration.',
            },

            'reports' => match ($action) {
                'view' =>
                    'View reports.',

                'create' =>
                    'Create reports.',

                'export' =>
                    'Export reports.',

                default =>
                    'Manage reports.',
            },

            'users' => match ($action) {
                'view' =>
                    'View users.',

                'create' =>
                    'Create users.',

                'update' =>
                    'Update users.',

                'delete' =>
                    'Delete users.',

                default =>
                    'Manage users.',
            },

            'roles' => match ($action) {
                'view' =>
                    'View roles.',

                'create' =>
                    'Create roles.',

                'update' =>
                    'Update roles.',

                'delete' =>
                    'Delete roles.',

                default =>
                    'Manage roles.',
            },

            'permissions' => match ($action) {
                'view' =>
                    'View permissions.',

                'manage' =>
                    'Manage permissions.',

                default =>
                    'Manage permissions.',
            },

            'system_settings' => match ($action) {
                'view' =>
                    'View system settings.',

                'manage' =>
                    'Manage system settings.',

                default =>
                    'Manage system settings.',
            },

            'audit' =>
                'View audit information.',

            default =>
                Str::headline($action)
                . ' '
                . Str::headline($resource)
                . '.',
        };
    }

    /**
     * Resolve the human-readable action label.
     */
    protected function resolveActionLabel(string $action): string
    {
        return match ($action) {
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'manage' => 'Manage',
            'export' => 'Export',
            default => Str::headline($action),
        };
    }
}
