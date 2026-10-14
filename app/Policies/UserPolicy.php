<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view another user.
     */
    public function view(User $actor, User $user): bool
    {
        return $actor->can('users.view');
    }


    /**
     * Determine whether the user can update another user.
     */
    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.update');
    }


    /**
     * Determine whether the user can update account status.
     */
    public function updateStatus(User $actor, User $user): bool
    {
        return $actor->can('users.update_status')
            && $actor->id !== $user->id;
    }


    /**
     * Determine whether the user can reset a password.
     */
    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->can('users.reset_password')
            && $actor->id !== $user->id;
    }


    /**
     * Determine whether the user can unlock an account.
     */
    public function unlock(User $actor, User $user): bool
    {
        return $actor->can('users.unlock');
    }


    /**
     * Determine whether the user can delete another user.
     */
    public function delete(User $actor, User $user): bool
    {
        return $actor->can('users.delete')
            && $actor->id !== $user->id;
    }
}