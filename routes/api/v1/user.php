<?php

use  App\Modules\Administration\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;


/* |-------------------------------------------------------------------------- |
       User Management |-------------------------------------------------------------------------- |
    | All user-management endpoints require an authenticated user. |
*/

Route::prefix('users')
    ->group(function () {
                /*
                |--------------------------------------------------------------------------
                | User Listing
                |--------------------------------------------------------------------------
                */

                // GET /api/v1/users
                //
                // Search, filter, sort and paginate users.
                Route::get('/', [
                    UserManagementController::class,
                    'index',
                ])->name('users.index');


                /*
                |--------------------------------------------------------------------------
                | Create User
                |--------------------------------------------------------------------------
                */

                // POST /api/v1/users
                Route::post('/', [
                    UserManagementController::class,
                    'store',
                ])->name('users.store');


                /*
                |--------------------------------------------------------------------------
                | Show User
                |--------------------------------------------------------------------------
                */

                // GET /api/v1/users/{user}
                Route::get('{user}', [
                    UserManagementController::class,
                    'show',
                ])->name('users.show');


                /*
                |--------------------------------------------------------------------------
                | Update User
                |--------------------------------------------------------------------------
                |
                | Updates non-security profile information such as:
                |
                | - name
                | - email
                |
                | Roles are intentionally managed separately.
                |
                */

                // PUT/PATCH /api/v1/users/{user}
                Route::match(
                    ['put', 'patch'],
                    '{user}',
                    [
                        UserManagementController::class,
                        'update',
                    ]
                )->name('users.update');


                /*
                |--------------------------------------------------------------------------
                | Account Status
                |--------------------------------------------------------------------------
                |
                | Activate / deactivate a user account.
                |
                */

                // PATCH /api/v1/users/{user}/status
                Route::patch('{user}/status', [
                    UserManagementController::class,
                    'updateStatus',
                ])->name('users.status');


                /*
                |--------------------------------------------------------------------------
                | Password Management
                |--------------------------------------------------------------------------
                |
                | Administrative password reset.
                |
                */

                // POST /api/v1/users/{user}/reset-password
                Route::post('{user}/reset-password', [
                    UserManagementController::class,
                    'resetPassword',
                ])->name('users.reset-password');


                /*
                |--------------------------------------------------------------------------
                | Account Unlock
                |--------------------------------------------------------------------------
                |
                | Clears failed login attempts and the lockout timestamp.
                |
                */

                // POST /api/v1/users/{user}/unlock
                Route::post('{user}/unlock', [
                    UserManagementController::class,
                    'unlock',
                ])->name('users.unlock');


                /*
                |--------------------------------------------------------------------------
                | Delete User
                |--------------------------------------------------------------------------
                |
                | Uses Laravel SoftDeletes.
                |
                */

                // DELETE /api/v1/users/{user}
                Route::delete('{user}', [
                    UserManagementController::class,
                    'destroy',
                ])->name('users.destroy');
});

