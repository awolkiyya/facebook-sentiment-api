<?php

use App\Modules\Administration\Controllers\PermissionManagementController;


Route::get(
    '/permissions',
    [PermissionManagementController::class, 'index']
);