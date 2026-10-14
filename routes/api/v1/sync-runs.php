<?php

declare(strict_types=1);

use App\Modules\SyncRun\Controllers\FacebookSyncRunController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync-runs')
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Facebook Synchronization Runs
        |--------------------------------------------------------------------------
        |
        | These endpoints are monitoring/history and synchronization
        | lifecycle-management endpoints.
        |
        | They do NOT start a new synchronization from scratch.
        |
        | Synchronization is started by the appropriate Facebook endpoints:
        |
        | POST /facebook/pages/{facebook_page_id}/posts/sync
        | POST /facebook/pages/{facebook_page_id}/posts/{post_id}/comments/sync
        |
        */

        /*
        |--------------------------------------------------------------------------
        | List Synchronization Runs
        |--------------------------------------------------------------------------
        |
        | GET /api/v1/sync-runs
        |
        */
        Route::get(
            '/',
            [FacebookSyncRunController::class, 'index']
        )->name('facebook.sync-runs.index');

        /*
        |--------------------------------------------------------------------------
        | Show Synchronization Run
        |--------------------------------------------------------------------------
        |
        | GET /api/v1/sync-runs/{id}
        |
        */
        Route::get(
            '/{id}',
            [FacebookSyncRunController::class, 'show']
        )->name('facebook.sync-runs.show');

        /*
        |--------------------------------------------------------------------------
        | Retry Synchronization Run
        |--------------------------------------------------------------------------
        |
        | POST /api/v1/sync-runs/{id}/retry
        |
        | Intended for failed/cancelled synchronization runs.
        |
        | The controller/service must verify that the run is eligible
        | for retry and that another active synchronization does not
        | already exist for the same synchronization target.
        |
        */
        Route::post(
            '/{id}/retry',
            [FacebookSyncRunController::class, 'retry']
        )->name('facebook.sync-runs.retry');

        /*
        |--------------------------------------------------------------------------
        | Cancel Synchronization Run
        |--------------------------------------------------------------------------
        |
        | DELETE /api/v1/sync-runs/{id}
        |
        | Intended for queued/running synchronization runs.
        |
        | The actual cancellation logic is handled by the controller
        | and FacebookSyncRunService.
        |
        */
        Route::delete(
            '/{id}',
            [FacebookSyncRunController::class, 'destroy']
        )->name('facebook.sync-runs.destroy');
    });