<?php

use App\Http\Controllers\MfaController;
use Illuminate\Support\Facades\Route;

Route::prefix('security/mfa')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated MFA Management
    |--------------------------------------------------------------------------
    |
    | These endpoints require an already authenticated user.
    |
    */

    Route::get(
        '/',
        [MfaController::class, 'status']
    );

    Route::post(
        '/setup',
        [MfaController::class, 'setup']
    );

    Route::post(
        '/confirm',
        [MfaController::class, 'confirm']
    );


    /*
    |--------------------------------------------------------------------------
    | MFA Login Verification
    |--------------------------------------------------------------------------
    |
    | This happens BEFORE the user is authenticated.
    | Therefore this route must NOT use auth:sanctum.
    |
    */

    Route::post(
        '/security/mfa/verify',
        [MfaController::class, 'verify']
    );


    Route::get(
        '/recovery-codes',
        [MfaController::class, 'recoveryCodesStatus']
    );

    Route::post(
        '/recovery-codes/regenerate',
        [MfaController::class, 'regenerateRecoveryCodes']
    );

    Route::post(
        '/disable',
        [MfaController::class, 'disable']
    );

    Route::post(
        '/cancel',
        [MfaController::class, 'cancelSetup']
    );
});