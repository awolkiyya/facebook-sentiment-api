<?php
use App\Modules\SystemSetting\Controllers\SystemSettingController;


 Route::get(
            '/system-settings',
            [SystemSettingController::class, 'show']
        );

        Route::match(
            ['put', 'patch'],
            '/system-settings',
            [SystemSettingController::class, 'update']
 );
