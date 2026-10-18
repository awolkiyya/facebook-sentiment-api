<?php

use Illuminate\Support\Facades\Route;
use App\Services\ApiResponse;
use App\Http\Controllers\MfaController;

// API HEALTH CHECK
Route::get('/ping', function () {
    return ApiResponse::success(
        message: 'City Mayor Facebook Analytics API is running.',
        data: [
            'status' => 'ok',
            'service' => 'City Mayor Facebook Analytics',
        ],
    );
});

// API VERSION 1
Route::prefix('v1')->group(function () {

    // PUBLIC ROUTES
    require base_path('routes/api/v1/auth.php');

    require base_path('routes/api/v1/facebook.php');

    /*
    |--------------------------------------------------------------------------
    | PROTECTED ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        require base_path('routes/api/v1/role.php');
        require base_path('routes/api/v1/permission.php');
        require base_path('routes/api/v1/user.php');
        require base_path('routes/api/v1/audit.php');
        require base_path('routes/api/v1/system-settings.php');
        require base_path('routes/api/v1/security.php');
        require base_path('routes/api/v1/notifications.php');
        require base_path('routes/api/v1/ai-models.php');
        require base_path('routes/api/v1/sync-runs.php');
        require base_path('routes/api/v1/ai-insight.php');


    });
});