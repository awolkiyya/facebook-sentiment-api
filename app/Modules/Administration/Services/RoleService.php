<?php

namespace App\Modules\Administration\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RoleService
{
    /**
     * API guard used by the administration authorization system.
     */
    private const GUARD = 'api';

    /**
     * Get paginated API roles.
     */
    public function paginate(
        array $filters = []
    ): LengthAwarePaginator {
        Log::info(
            'RoleService: starting role pagination.',
            [
                'filters' => $filters,
                'guard' => self::GUARD,
            ]
        );

        $query = Role::query()
            ->where(
                'guard_name',
                self::GUARD
            )
            ->withCount('users')
            ->withCount('permissions');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['search'])) {
            $search = trim(
                (string) $filters['search']
            );

            if ($search !== '') {
                $query->where(
                    function ($q) use ($search) {
                        $q->where(
                            'name',
                            'ilike',
                            "%{$search}%"
                        );
                    }
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $allowedSorts = [
            'name',
            'created_at',
            'updated_at',
            'users_count',
            'permissions_count',
        ];

        $sortBy =
            $filters['sort_by']
            ?? 'created_at';

        if (
            ! in_array(
                $sortBy,
                $allowedSorts,
                true
            )
        ) {
            $sortBy = 'created_at';
        }

        $sortDirection =
            $filters['sort_direction']
            ?? 'desc';

        if (
            ! in_array(
                $sortDirection,
                [
                    'asc',
                    'desc',
                ],
                true
            )
        ) {
            $sortDirection = 'desc';
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = min(
            max(
                (int) (
                    $filters['per_page']
                    ?? 20
                ),
                1
            ),
            100
        );

        try {
            $result = $query
                ->orderBy(
                    $sortBy,
                    $sortDirection
                )
                ->paginate(
                    $perPage
                )
                ->withQueryString();

            Log::info(
                'RoleService: role pagination successful.',
                [
                    'total' => $result->total(),
                    'current_page' =>
                        $result->currentPage(),
                    'per_page' =>
                        $result->perPage(),
                ]
            );

            return $result;
        } catch (Throwable $exception) {
            Log::error(
                'RoleService: role pagination failed.',
                [
                    'exception_class' =>
                        get_class($exception),
                    'exception_message' =>
                        $exception->getMessage(),
                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Find a single API role with its permissions.
     *
     * The returned Role model contains:
     *
     * - users_count
     * - permissions_count
     * - permissions
     *
     * The permissions relationship is intentionally loaded here
     * because the edit form needs the currently assigned permissions.
     */
    public function find(
        string|int $roleId
    ): Role {
        Log::info(
            'RoleService: finding role.',
            [
                'role_id' => $roleId,
                'guard' => self::GUARD,
            ]
        );

        try {
            $role = Role::query()
                ->where(
                    'guard_name',
                    self::GUARD
                )
                ->with([
                    'permissions' => function ($query) {
                        $query
                            ->where(
                                'guard_name',
                                self::GUARD
                            )
                            ->select([
                                'id',
                                'name',
                                'guard_name',
                            ])
                            ->orderBy(
                                'name'
                            );
                    },
                ])
                ->withCount('users')
                ->withCount('permissions')
                ->findOrFail(
                    $roleId
                );

            Log::info(
                'RoleService: role found successfully.',
                [
                    'role_id' =>
                        $role->id,

                    'role_name' =>
                        $role->name,

                    'role_type' =>
                        $role->is_system
                            ? 'SYSTEM'
                            : 'CUSTOM',

                    'users_count' =>
                        $role->users_count,

                    'permissions_count' =>
                        $role->permissions_count,

                    'loaded_permissions_count' =>
                        $role->permissions->count(),

                    'permissions' =>
                        $role->permissions
                            ->pluck('name')
                            ->values()
                            ->all(),
                ]
            );

            return $role;
        } catch (Throwable $exception) {
            Log::error(
                'RoleService: finding role failed.',
                [
                    'role_id' =>
                        $roleId,

                    'exception_class' =>
                        get_class($exception),

                    'exception_message' =>
                        $exception->getMessage(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Create a role with its permissions.
     */
    public function create(
        array $data
    ): Role {
        $name = trim(
            (string) (
                $data['name']
                ?? ''
            )
        );

        $permissions =
            $this->normalizePermissions(
                $data['permissions']
                ?? []
            );

        Log::info(
            'RoleService: creating role.',
            [
                'role_name' => $name,
                'permissions_count' =>
                    count($permissions),
                'permissions' =>
                    $permissions,
            ]
        );

        $this->validateRoleName(
            $name
        );

        return DB::transaction(
            function () use (
                $name,
                $permissions
            ): Role {
                /*
                |--------------------------------------------------------------------------
                | Validate Permissions
                |--------------------------------------------------------------------------
                */

                $this->validatePermissions(
                    $permissions
                );

                /*
                |--------------------------------------------------------------------------
                | Create Role
                |--------------------------------------------------------------------------
                */

                $role = Role::create([
                    'name' => $name,
                    'guard_name' =>
                        self::GUARD,
                    'is_system' => false,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Assign Permissions
                |--------------------------------------------------------------------------
                */

                $role->syncPermissions(
                    $permissions
                );

                Log::info(
                    'RoleService: role created successfully.',
                    [
                        'role_id' =>
                            $role->id,

                        'role_name' =>
                            $role->name,

                        'permissions_count' =>
                            count($permissions),
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Return Complete Role
                |--------------------------------------------------------------------------
                */

                return $this->find(
                    $role->id
                );
            }
        );
    }

    /**
     * Replace the complete role configuration.
     *
     * PUT /v1/roles/{role}
     *
     * The permissions array represents the complete desired state.
     */
    public function update(
        Role $role,
        array $data
    ): Role {
        Log::info(
            'RoleService: updating role.',
            [
                'role_id' =>
                    $role->id,

                'role_name' =>
                    $role->name,

                'guard_name' =>
                    $role->guard_name,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Security Checks
        |--------------------------------------------------------------------------
        */

        $this->ensureApiGuard(
            $role
        );

        $this->ensureRoleCanBeModified(
            $role
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize Input
        |--------------------------------------------------------------------------
        */

        $name = trim(
            (string) (
                $data['name']
                ?? ''
            )
        );

        $permissions =
            $this->normalizePermissions(
                $data['permissions']
                ?? []
            );

        $this->validateRoleName(
            $name
        );

        return DB::transaction(
            function () use (
                $role,
                $name,
                $permissions
            ): Role {
                /*
                |--------------------------------------------------------------------------
                | Validate Permissions
                |--------------------------------------------------------------------------
                */

                $this->validatePermissions(
                    $permissions
                );

                /*
                |--------------------------------------------------------------------------
                | Update Role Name
                |--------------------------------------------------------------------------
                */

                $role->update([
                    'name' => $name,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Completely Synchronize Permissions
                |--------------------------------------------------------------------------
                |
                | Important:
                |
                | syncPermissions() replaces the current
                | permission assignment with exactly the
                | submitted permissions.
                |
                */

                $role->syncPermissions(
                    $permissions
                );

                Log::info(
                    'RoleService: role updated successfully.',
                    [
                        'role_id' =>
                            $role->id,

                        'role_name' =>
                            $role->name,

                        'permissions_count' =>
                            count($permissions),

                        'permissions' =>
                            $permissions,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Return Fresh Role
                |--------------------------------------------------------------------------
                */

                return $this->find(
                    $role->id
                );
            }
        );
    }

    /**
     * Delete a role.
     */
    public function delete(
        Role $role
    ): void {
        Log::info(
            'RoleService: deleting role.',
            [
                'role_id' =>
                    $role->id,

                'role_name' =>
                    $role->name,

                'guard_name' =>
                    $role->guard_name,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Security Checks
        |--------------------------------------------------------------------------
        */

        $this->ensureApiGuard(
            $role
        );

        $this->ensureRoleCanBeDeleted(
            $role
        );

        /*
        |--------------------------------------------------------------------------
        | User Assignment Check
        |--------------------------------------------------------------------------
        */

        try {
            $hasUsers =
                $role
                    ->users()
                    ->exists();
        } catch (Throwable $exception) {
            Log::error(
                'RoleService: checking role users failed.',
                [
                    'role_id' =>
                        $role->id,

                    'exception_class' =>
                        get_class($exception),

                    'exception_message' =>
                        $exception->getMessage(),

                    'trace' =>
                        $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }

        if ($hasUsers) {
            throw new InvalidArgumentException(
                'This role cannot be deleted because it is assigned to users.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Delete
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            function () use (
                $role
            ): void {
                /*
                 * Remove all permission assignments first.
                 */
                $role->syncPermissions(
                    []
                );

                /*
                 * Delete the role.
                 */
                $role->delete();

                Log::info(
                    'RoleService: role deleted successfully.',
                    [
                        'role_id' =>
                            $role->id,
                    ]
                );
            }
        );
    }

    /**
     * Normalize submitted permissions.
     *
     * Removes:
     * - duplicates
     * - empty values
     * - whitespace around names
     */
    protected function normalizePermissions(
        mixed $permissions
    ): array {
        if (! is_array($permissions)) {
            return [];
        }

        $normalized = [];

        foreach ($permissions as $permission) {
            if (
                ! is_string(
                    $permission
                )
            ) {
                continue;
            }

            $permission = trim(
                $permission
            );

            if ($permission === '') {
                continue;
            }

            $normalized[] =
                $permission;
        }

        return array_values(
            array_unique(
                $normalized
            )
        );
    }

    /**
     * Validate role name.
     */
    protected function validateRoleName(
        string $name
    ): void {
        if ($name === '') {
            throw new InvalidArgumentException(
                'The role name is required.'
            );
        }

        /*
         * Role names use the same convention
         * as the frontend:
         *
         * UPPERCASE_WITH_UNDERSCORES
         */
        if (
            ! preg_match(
                '/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/',
                $name
            )
        ) {
            throw new InvalidArgumentException(
                'The role name must contain only uppercase letters, numbers, and underscores.'
            );
        }
    }

    /**
     * Validate that every selected permission
     * exists for the API guard.
     */
    protected function validatePermissions(
        array $permissions
    ): void {
        if (empty($permissions)) {
            return;
        }

        $validPermissions =
            Permission::query()
                ->where(
                    'guard_name',
                    self::GUARD
                )
                ->whereIn(
                    'name',
                    $permissions
                )
                ->pluck(
                    'name'
                )
                ->all();

        $invalidPermissions =
            array_values(
                array_diff(
                    $permissions,
                    $validPermissions
                )
            );

        if (
            ! empty(
                $invalidPermissions
            )
        ) {
            Log::warning(
                'RoleService: invalid permissions supplied.',
                [
                    'invalid_permissions' =>
                        $invalidPermissions,
                ]
            );

            throw new InvalidArgumentException(
                'One or more selected permissions are invalid.'
            );
        }
    }

    /**
     * Ensure the role belongs to the API guard.
     */
    protected function ensureApiGuard(
        Role $role
    ): void {
        if (
            $role->guard_name !==
            self::GUARD
        ) {
            throw new InvalidArgumentException(
                'The selected role does not belong to the API guard.'
            );
        }
    }

    /**
     * Protect system roles from modification.
     */
    protected function ensureRoleCanBeModified(
        Role $role
    ): void {
        if (
            $role->is_system
        ) {
            throw new InvalidArgumentException(
                'System roles cannot be modified.'
            );
        }
    }

    /**
     * Protect system roles from deletion.
     */
    protected function ensureRoleCanBeDeleted(
        Role $role
    ): void {
        if (
            $role->is_system
        ) {
            throw new InvalidArgumentException(
                'System roles cannot be deleted.'
            );
        }
    }
}
