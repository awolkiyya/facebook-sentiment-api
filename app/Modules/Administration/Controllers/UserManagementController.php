<?php

namespace App\Modules\Administration\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Requests\User\AssignUserRolesRequest;
use App\Modules\Administration\Requests\User\ListUsersRequest;
use App\Modules\Administration\Requests\User\ResetUserPasswordRequest;
use App\Modules\Administration\Requests\User\StoreUserRequest;
use App\Modules\Administration\Requests\User\UpdateUserRequest;
use App\Modules\Administration\Requests\User\UpdateUserStatusRequest;
use App\Modules\Administration\Resources\UserResource;
use App\Modules\Administration\Services\UserService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {
    }

    /**
     * Display a paginated list of users.
     */
    public function index(
        ListUsersRequest $request
    ): AnonymousResourceCollection {
        $users = $this->userService->paginate(
            $request->validated()
        );

        return UserResource::collection($users);
    }

    /**
     * Store a newly created user.
     */
    public function store(
        StoreUserRequest $request
    ): JsonResponse {
        $user = $this->userService->create(
            $request->validated()
        );

        return ApiResponse::created(
            data: new UserResource(
                $user->load('roles')
            ),
            message: 'User created successfully.'
        );
    }

    /**
     * Display the specified user.
     */
    public function show(
        User $user
    ): JsonResponse {
        $this->authorize('view', $user);

        return ApiResponse::success(
            data: new UserResource(
                $user->load('roles')
            ),
            message: 'User retrieved successfully.'
        );
    }

    /**
     * Update the specified user.
     */
    public function update(
        UpdateUserRequest $request,
        User $user
    ): JsonResponse {
        $this->authorize('update', $user);

        $updatedUser = $this->userService->update(
            $user,
            $request->validated()
        );

        return ApiResponse::updated(
            data: new UserResource(
                $updatedUser->load('roles')
            ),
            message: 'User updated successfully.'
        );
    }

    /**
     * Update the user's active status.
     */
    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user
    ): JsonResponse {
        $this->authorize('updateStatus', $user);

        $updatedUser = $this->userService->updateStatus(
            $user,
            $request->boolean('is_active')
        );

        return ApiResponse::updated(
            data: new UserResource(
                $updatedUser->load('roles')
            ),
            message: $updatedUser->is_active
                ? 'User activated successfully.'
                : 'User deactivated successfully.'
        );
    }

    /**
     * Assign roles to the user.
     */
    public function assignRoles(
        AssignUserRolesRequest $request,
        User $user
    ): JsonResponse {
        $this->authorize('assignRoles', $user);

        $updatedUser = $this->userService->syncRoles(
            $user,
            $request->validated('roles')
        );

        return ApiResponse::updated(
            data: new UserResource(
                $updatedUser->load('roles')
            ),
            message: 'User roles updated successfully.'
        );
    }

    /**
     * Reset the user's password.
     */
    public function resetPassword(
        ResetUserPasswordRequest $request,
        User $user
    ): JsonResponse {
        $this->authorize('resetPassword', $user);

        $updatedUser = $this->userService->resetPassword(
            $user,
            $request->string('password')->toString()
        );

        return ApiResponse::success(
            data: new UserResource(
                $updatedUser->load('roles')
            ),
            message: 'User password reset successfully.'
        );
    }

    /**
     * Unlock the user's account.
     */
    public function unlock(
        User $user
    ): JsonResponse {
        $this->authorize('unlock', $user);

        $updatedUser = $this->userService->unlock($user);

        return ApiResponse::success(
            data: new UserResource(
                $updatedUser->load('roles')
            ),
            message: 'User account unlocked successfully.'
        );
    }

    /**
     * Soft delete the specified user.
     */
    public function destroy(
        User $user
    ): JsonResponse {
        $this->authorize('delete', $user);

        $this->userService->delete($user);

        return ApiResponse::deleted(
            message: 'User deleted successfully.'
        );
    }
}