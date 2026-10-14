<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

class NotificationPolicy
{
    /**
     * Determine whether the user can view the notifications list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view');
    }

    /**
     * Determine whether the user can read/view a specific notification.
     */
    public function view(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.read');
    }

    /**
     * Determine whether the user can create notifications.
     */
    public function create(User $user): bool
    {
        return $user->can('notifications.create');
    }

    /**
     * Determine whether the user can update a notification.
     */
    public function update(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.update');
    }

    /**
     * Determine whether the user can delete a notification.
     */
    public function delete(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.delete');
    }

    /**
     * Determine whether the user can publish a notification.
     */
    public function publish(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.publish');
    }

    /**
     * Determine whether the user can schedule a notification.
     */
    public function schedule(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.schedule');
    }

    /**
     * Determine whether the user can archive a notification.
     */
    public function archive(
        User $user,
        Notification $notification
    ): bool {
        return $user->can('notifications.archive');
    }
}