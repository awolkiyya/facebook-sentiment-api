<?php

use App\Http\Controllers\SystemLogController;
use Illuminate\Support\Facades\Route;

/*
* System Logs
*/
Route::prefix('system-logs')
    ->group(function () {
        Route::get(
            '/',
            [SystemLogController::class, 'index']
        );

        Route::get(
            '/{systemLog}',
            [SystemLogController::class, 'show']
        );
});
