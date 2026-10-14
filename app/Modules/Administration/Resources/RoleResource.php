<?php

namespace App\Modules\Administration\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the role into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,

            'name' => (string) $this->name,

            'guard_name' => (string) $this->guard_name,

            /*
             * Semantic role type exposed to the frontend.
             *
             * SYSTEM:
             *   Protected system role such as SYSTEM_ADMIN.
             *
             * CUSTOM:
             *   User-created role.
             */
            'type' => $this->is_system
                ? 'SYSTEM'
                : 'CUSTOM',

            /*
             * Loaded by RoleService::paginate() / find().
             */
            'users_count' => $this->when(
                isset($this->users_count),
                fn (): int => (int) $this->users_count
            ),

            /*
             * Loaded by RoleService::paginate() / find().
             */
            'permissions_count' => $this->when(
                isset($this->permissions_count),
                fn (): int => (int) $this->permissions_count
            ),

            /*
             * Only included when the permissions relationship
             * has been explicitly eager-loaded.
             *
             * This is important for the edit form because the
             * frontend needs the actual assigned permission names.
             */
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions
                    ->map(
                        fn ($permission): array => [
                            'id' => (string) $permission->id,
                            'name' => (string) $permission->name,
                        ]
                    )
                    ->values()
                    ->all()
            ),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
