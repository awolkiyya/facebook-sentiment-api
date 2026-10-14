<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    private ?string $token;

    public function __construct(
        $resource,
        ?string $token = null
    ) {
        parent::__construct($resource);

        $this->token = $token;
    }

    public function toArray(Request $request): array
    {
        return [
            /*
             |--------------------------------------------------------------------------
             | User Identity
             |--------------------------------------------------------------------------
             */
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,

            /*
             |--------------------------------------------------------------------------
             | MFA
             |--------------------------------------------------------------------------
             */
            'mfa_enabled' => (bool) $this->mfa_enabled,

            /*
             |--------------------------------------------------------------------------
             | Role
             |--------------------------------------------------------------------------
             */
            'role' => $this->when(
                $this->relationLoaded('roles'),
                function () {
                    $role = $this->roles->first();

                    if (!$role) {
                        return null;
                    }

                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'label' => $role->label ?? $role->name,
                    ];
                }
            ),

            /*
             |--------------------------------------------------------------------------
             | Permissions
             |--------------------------------------------------------------------------
             */
            'permissions' => $this->formatPermissions(),

            /*
             |--------------------------------------------------------------------------
             | Timestamps
             |--------------------------------------------------------------------------
             */
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            /*
             |--------------------------------------------------------------------------
             | Mobile Access Token
             |--------------------------------------------------------------------------
             |
             | Only included when a token was explicitly provided.
             |
             */
            'access_token' => $this->when(
                $this->token !== null,
                $this->token
            ),

            'token_type' => $this->when(
                $this->token !== null,
                'Bearer'
            ),
        ];
    }

    /**
     * Format permissions by resource and action.
     */
    private function formatPermissions(): array
    {
        if (!$this->relationLoaded('roles')) {
            return [];
        }

        $grouped = [];

        foreach ($this->getAllPermissions() as $permission) {

            $parts = explode(
                '.',
                $permission->name
            );

            $resource = array_shift($parts) ?? 'general';

            $action = implode(
                '.',
                $parts
            );

            if ($action === '') {
                $action = $permission->name;
            }

            if (!isset($grouped[$resource])) {
                $grouped[$resource] = [
                    'resource' => $resource,
                    'actions' => [],
                ];
            }

            if (!in_array(
                $action,
                $grouped[$resource]['actions'],
                true
            )) {
                $grouped[$resource]['actions'][] = $action;
            }
        }

        return array_values($grouped);
    }

    /**
     * Resolve user avatar URL.
     */
    private function resolveAvatar(): ?string
    {
        if (!$this->avatar) {
            return null;
        }

        return asset(
            $this->avatar->path
        );
    }
}
