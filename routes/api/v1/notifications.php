<?php

use App\Modules\Administration\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/statistics',
        [
            NotificationController::class,
            'statistics',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | My Notifications
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/mine',
        [
            NotificationController::class,
            'mine',
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Read Notifications
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/read-all',
        [
            NotificationController::class,
            'readAll',
        ]
    );

    Route::post(
        '/{notification}/read',
        [
            NotificationController::class,
            'read',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | List
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/',
        [
            NotificationController::class,
            'index',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/',
        [
            NotificationController::class,
            'store',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Read / View
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/{notification}',
        [
            NotificationController::class,
            'show',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    Route::put(
        '/{notification}',
        [
            NotificationController::class,
            'update',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    Route::delete(
        '/{notification}',
        [
            NotificationController::class,
            'destroy',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Publish
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/{notification}/publish',
        [
            NotificationController::class,
            'publish',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/{notification}/schedule',
        [
            NotificationController::class,
            'schedule',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/{notification}/archive',
        [
            NotificationController::class,
            'archive',
        ]
    );
});