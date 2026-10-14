<?php

namespace App\Policies;

use App\Models\User;
use App\Models\SystemSetting;

class SystemSettingPolicy
{
    /**
     * View system settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(
            'system_settings.view'
        );
    }

    /**
     * View system settings.
     */
    public function view(User $user): bool
    {
        return $user->can(
            'system_settings.view'
        );
    }

    /**
     * Update system settings.
     */
    public function update(User $user): bool
    {
        return $user->can(
            'system_settings.manage'
        );
    }
}
