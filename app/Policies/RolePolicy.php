<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Determine whether the user can view
     * the roles list.
     */
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            'roles.view'
        );
    }

    /**
     * Determine whether the user can view
     * a specific role.
     */
    public function view(
        User $user,
        Role $role
    ): bool {
        return $user->can(
            'roles.view'
        )
            && $role->guard_name === 'api';
    }

    /**
     * Determine whether the user can create
     * a new role.
     */
    public function create(
        User $user
    ): bool {
        return $user->can(
            'roles.create'
        );
    }

    /**
     * Determine whether the user can update
     * a role.
     *
     * SYSTEM_ADMIN is protected.
     */
    public function update(
        User $user,
        Role $role
    ): bool {
        return $user->can(
            'roles.update'
        )
            && $role->guard_name === 'api'
            && $role->name !== 'SYSTEM_ADMIN';
    }

    /**
     * Determine whether the user can delete
     * a role.
     *
     * SYSTEM_ADMIN is protected.
     */
    public function delete(
        User $user,
        Role $role
    ): bool {
        return $user->can(
            'roles.delete'
        )
            && $role->guard_name === 'api'
            && $role->name !== 'SYSTEM_ADMIN';
    }
}