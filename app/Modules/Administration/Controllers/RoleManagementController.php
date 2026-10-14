<?php

namespace App\Modules\Administration\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Administration\Requests\Role\ListRolesRequest;
use App\Modules\Administration\Requests\Role\StoreRoleRequest;
use App\Modules\Administration\Requests\Role\UpdateRoleRequest;
use App\Modules\Administration\Resources\RoleResource;
use App\Modules\Administration\Services\RoleService;
use App\Services\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoleManagementController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService
    ) {
    }

    /**
     * Display a paginated list of roles.
     */
    public function index(
        ListRolesRequest $request
    ): JsonResponse {
        $roles = $this->roleService->paginate(
            $request->validated()
        );

        return ApiResponse::success(
            data: RoleResource::collection($roles),
            message: 'Roles retrieved successfully.'
        );
    }

    /**
     * Create a new role.
     */
    public function store(
        StoreRoleRequest $request
    ): JsonResponse {
        $role = $this->roleService->create(
            $request->validated()
        );

        return ApiResponse::created(
            data: new RoleResource($role),
            message: 'Role created successfully.'
        );
    }

    /**
     * Display a single role.
     */
    public function show(
        Role $role
    ): JsonResponse {
        Gate::authorize('view', $role);

        $role = $this->roleService->find(
            $role->id
        );

        return ApiResponse::success(
            data: new RoleResource($role),
            message: 'Role retrieved successfully.'
        );
    }

    /**
     * Update an existing role.
     */
    public function update(
        UpdateRoleRequest $request,
        Role $role
    ): JsonResponse {
        Gate::authorize('update', $role);

        $updatedRole = $this->roleService->update(
            $role,
            $request->validated()
        );

        return ApiResponse::updated(
            data: new RoleResource($updatedRole),
            message: 'Role updated successfully.'
        );
    }

    /**
     * Delete a role.
     */
    public function destroy(
        Role $role
    ): JsonResponse {
        Gate::authorize('delete', $role);

        $this->roleService->delete($role);

        return ApiResponse::deleted(
            message: 'Role deleted successfully.'
        );
    }
}