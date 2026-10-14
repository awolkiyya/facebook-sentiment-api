<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\OtpController;
use App\Modules\Auth\Controllers\SessionController;


/*
|--------------------------------------------------------------------------
| API V1 Authentication Routes
|--------------------------------------------------------------------------
|
| Prefix:
|     /api/v1/auth
|
|--------------------------------------------------------------------------
| AUTHENTICATION ARCHITECTURE
|--------------------------------------------------------------------------
|
| WEB
| ---
| Laravel Sanctum Stateful Authentication
|
| Login flow:
|
|     Email + Password
|          ↓
|     Credential validation
|          ↓
|     MFA enabled?
|       ├── No  → Laravel session
|       │
|       └── Yes → MFA challenge
|                    ↓
|                 MFA verification
|                    ↓
|                 Laravel session
|
| Middleware for protected routes:
|
|     web
|     log.auth
|     auth:sanctum
|
| Browser credentials:
|
|     laravel-session
|     XSRF-TOKEN
|
|
| MOBILE
| ------
| Laravel Sanctum Personal Access Token
|
| Middleware:
|
|     auth:sanctum
|
| Flutter credentials:
|
|     Authorization: Bearer {token}
|
|--------------------------------------------------------------------------
*/


Route::prefix('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | WEB AUTHENTICATION
    |--------------------------------------------------------------------------
    |
    | Next.js / Browser
    |
    | Authentication:
    |     Laravel Sanctum Stateful Authentication
    |
    | Credentials:
    |     laravel-session
    |     XSRF-TOKEN
    |
    |--------------------------------------------------------------------------
    */


    /*
    |--------------------------------------------------------------------------
    | WEB EMPLOYEE LOGIN
    |--------------------------------------------------------------------------
    |
    | POST:
    |     /api/v1/auth/web-login
    |
    | Authentication:
    |     None before login
    |
    | Flow:
    |
    |     Email + Password
    |          ↓
    |     Credential validation
    |          ↓
    |     MFA disabled?
    |       ├── Yes → Create Laravel session
    |       │
    |       └── No  → Create MFA challenge
    |
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/web-login',
        [AuthController::class, 'webLogin']
    )
        ->middleware('throttle:login')
        ->name('auth.web-login');


    /*
    |--------------------------------------------------------------------------
    | WEB MFA LOGIN VERIFICATION
    |--------------------------------------------------------------------------
    |
    | POST:
    |     /api/v1/auth/mfa/verify
    |
    | Authentication:
    |     None before MFA verification
    |
    | IMPORTANT:
    |
    | This route MUST NOT use:
    |
    |     auth:sanctum
    |
    | because the Laravel authenticated session has not been
    | created yet.
    |
    | Flow:
    |
    |     challenge_id + MFA code
    |              ↓
    |     Validate MFA challenge
    |              ↓
    |     Verify TOTP / recovery code
    |              ↓
    |     Create Laravel session
    |
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/mfa/verify',
        [AuthController::class, 'verifyLoginMfa']
    )
        ->middleware('throttle:mfa')
        ->name('auth.mfa.verify');


    /*
    |--------------------------------------------------------------------------
    | WEB PROTECTED ROUTES
    |--------------------------------------------------------------------------
    |
    | Middleware:
    |
    |     log.auth
    |          ↓
    |     auth:sanctum
    |          ↓
    |     Controller
    |
    | These routes require a fully authenticated Laravel
    | session.
    |
    |--------------------------------------------------------------------------
    */

    Route::middleware([
        'log.auth',
        'auth:sanctum',
    ])->group(function () {


        /*
        |--------------------------------------------------------------------------
        | CURRENT WEB USER
        |--------------------------------------------------------------------------
        |
        | GET:
        |     /api/v1/auth/web-me
        |
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/web-me',
            [SessionController::class, 'webMe']
        )->name('auth.web-me');


        /*
        |--------------------------------------------------------------------------
        | WEB SESSION
        |--------------------------------------------------------------------------
        |
        | GET:
        |     /api/v1/auth/web-session
        |
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/web-session',
            [SessionController::class, 'webSession']
        )->name('auth.web-session');


        /*
        |--------------------------------------------------------------------------
        | WEB LOGOUT
        |--------------------------------------------------------------------------
        |
        | POST:
        |     /api/v1/auth/web-logout
        |
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/web-logout',
            [AuthController::class, 'webLogout']
        )->name('auth.web-logout');

    });

});