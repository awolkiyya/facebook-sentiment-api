<?php

namespace App\Policies;

use App\Models\SystemLog;
use App\Models\User;

class SystemLogPolicy
{
    /**
     * Determine whether the user can view any system logs.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    /**
     * Determine whether the user can view a system log.
     */
    public function view(User $user, SystemLog $systemLog): bool
    {
        return $user->can('audit.view');
    }
}