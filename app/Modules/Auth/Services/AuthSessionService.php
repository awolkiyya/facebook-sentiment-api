<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthSessionService
{
    /**
     * Authenticate the user using Laravel's web session.
     *
     * Used only by WEB authentication.
     *
     * The web middleware must be active before calling this method.
     */
    public function authenticate(
        Request $request,
        User $user,
        bool $remember = false
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Login Using Web Guard
        |--------------------------------------------------------------------------
        */

        Auth::guard('web')->login(
            $user,
            $remember
        );

        /*
        |--------------------------------------------------------------------------
        | Prevent Session Fixation
        |--------------------------------------------------------------------------
        |
        | Regenerate the session ID after authentication.
        |
        */

        $request->session()->regenerate();
    }

    /**
     * Check whether the current web session is authenticated.
     */
    public function check(): bool
    {
        return Auth::guard('web')->check();
    }

    /**
     * Get the authenticated web user.
     */
    public function user(): ?User
    {
        return Auth::guard('web')->user();
    }

    /**
     * Get the authenticated web user ID.
     */
    public function userId(): mixed
    {
        return Auth::guard('web')->id();
    }

    /**
     * Logout the current web session.
     */
    public function logout(Request $request): void
    {
        /*
        |--------------------------------------------------------------------------
        | Logout Web Guard
        |--------------------------------------------------------------------------
        */

        Auth::guard('web')->logout();

        /*
        |--------------------------------------------------------------------------
        | Invalidate Entire Session
        |--------------------------------------------------------------------------
        |
        | Removes the authenticated session and session data.
        |
        */

        $request->session()->invalidate();

        /*
        |--------------------------------------------------------------------------
        | Regenerate CSRF Token
        |--------------------------------------------------------------------------
        */

        $request->session()->regenerateToken();
    }
}