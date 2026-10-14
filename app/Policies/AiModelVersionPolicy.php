<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BaseModelVersion;
use App\Models\User;

class AiModelVersionPolicy
{
    /**
     * Determine whether the user can view any model versions.
     *
     * Permission:
     *
     *     ai_models.view
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ai_models.view');
    }

    /**
     * Determine whether the user can view a specific model version.
     *
     * Permission:
     *
     *     ai_models.view
     */
    public function view(
        User $user,
        BaseModelVersion $modelVersion,
    ): bool {
        return $user->can('ai_models.view');
    }

    /**
     * Determine whether the user can create a model version.
     *
     * Permission:
     *
     *     ai_models.create
     */
    public function create(User $user): bool
    {
        return $user->can('ai_models.create');
    }

    /**
     * Determine whether the user can update a model version.
     *
     * Permission:
     *
     *     ai_models.update
     */
    public function update(
        User $user,
        BaseModelVersion $modelVersion,
    ): bool {
        return $user->can('ai_models.update');
    }

    /**
     * Determine whether the user can activate a model version.
     *
     * Permission:
     *
     *     ai_models.activate
     */
    public function activate(
        User $user,
        BaseModelVersion $modelVersion,
    ): bool {
        return $user->can('ai_models.activate');
    }

    /**
     * Determine whether the user can delete a model version.
     *
     * Permission:
     *
     *     ai_models.delete
     */
    public function delete(
        User $user,
        BaseModelVersion $modelVersion,
    ): bool {
        return $user->can('ai_models.delete');
    }
}
