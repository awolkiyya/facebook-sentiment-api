<?php

namespace App\Modules\Auth\Services;

use App\Models\SystemSetting;
use App\Modules\Auth\Resources\AuthUserResource;
use Illuminate\Http\Request;

class SessionService
{
    /*
    |--------------------------------------------------------------------------
    | WEB
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated web user.
     *
     * Authentication:
     *
     *     Laravel web session
     *
     * Cookie:
     *
     *     laravel_session
     *
     * Returns:
     *
     *     [
     *         'user' => [...],
     *         'security' => [
     *             'require_two_factor' => bool,
     *             'mfa_setup_required' => bool,
     *         ],
     *     ]
     */
    public function webUser(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => (new AuthUserResource($user))->resolve($request),

            'security' => $this->securityContext($user),
        ];
    }

    /**
     * Get current web session information.
     */
    public function sessionInfo(Request $request): array
    {
        $user = $request->user('web');

        if (!$user) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'authenticated' => true,

            'user' => (new AuthUserResource($user))->resolve($request),

            'security' => $this->securityContext($user),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE
    |--------------------------------------------------------------------------
    */

    /**
     * Get authenticated mobile user.
     *
     * Authentication:
     *
     *     Sanctum personal access token
     *
     * Header:
     *
     *     Authorization: Bearer {token}
     */
    public function mobileUser(Request $request): array
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return [
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'user' => (new AuthUserResource(
                $user,
                $request->bearerToken()
            ))->resolve($request),

            'security' => $this->securityContext($user),
        ];
    }

    /**
     * Get current mobile authentication information.
     */
    public function mobileSessionInfo(Request $request): array
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        $this->loadUserRelations($user);

        return [
            'authenticated' => true,

            'user' => (new AuthUserResource(
                $user,
                $request->bearerToken()
            ))->resolve($request),

            'security' => $this->securityContext($user),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | SECURITY CONTEXT
    |--------------------------------------------------------------------------
    */

    /**
     * Get authentication/security policy for the current user.
     *
     * System setting:
     *
     *     require_two_factor
     *
     * User state:
     *
     *     mfa_enabled
     *
     * MFA setup is required only when:
     *
     *     system requires 2FA
     *     AND
     *     current user has not enabled MFA.
     */
    private function securityContext($user): array
    {
        $settings = SystemSetting::query()->first();

        $requireTwoFactor = (bool) (
            $settings?->require_two_factor ?? false
        );

        $mfaEnabled = (bool) $user->mfa_enabled;

        return [
            'require_two_factor' => $requireTwoFactor,

            'mfa_setup_required' =>
                $requireTwoFactor && !$mfaEnabled,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | USER RELATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Load relations required by the authenticated user.
     */
    private function loadUserRelations($user): void
    {
        /*
        |--------------------------------------------------------------------------
        | Common Relations
        |--------------------------------------------------------------------------
        */

        $relations = [
            // 'avatar',
            'roles.permissions',
        ];

        /*
        |--------------------------------------------------------------------------
        | Load Relations
        |--------------------------------------------------------------------------
        */

        $user->load($relations);
    }
}
