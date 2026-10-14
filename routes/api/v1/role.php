<?php
use Illuminate\Support\Facades\Route;

use App\Modules\Administration\Controllers\RoleManagementController;


 Route::prefix('roles')->group(function () {
            Route::get(
                '/',
                [RoleManagementController::class, 'index']
            )->name('roles.index');

            Route::post(
                '/',
                [RoleManagementController::class, 'store']
            )->name('roles.store');

            Route::get(
                '{role}',
                [RoleManagementController::class, 'show']
            )->name('roles.show');

            Route::match(
                ['put', 'patch'],
                '{role}',
                [RoleManagementController::class, 'update']
            )->name('roles.update');

            Route::delete(
                '{role}',
                [RoleManagementController::class, 'destroy']
            )->name('roles.destroy');
});

