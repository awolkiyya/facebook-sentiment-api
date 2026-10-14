<?php

namespace App\Modules\SystemSetting\Controllers;
use App\Http\Controllers\Controller;


use App\Modules\SystemSetting\Requests\UpdateSystemSettingRequest;
use App\Modules\SystemSetting\Resources\SystemSettingResource;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use App\Modules\SystemSetting\Services\SystemSettingService;
use App\Supports\SystemLogModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemSettingController extends Controller
{
    /**
     * Display the current system settings.
     *
     * GET /api/v1/system-settings
     */
    public function show(
        SystemSettingService $systemSettingService,
        SystemLogService $systemLogService
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'view',
            \App\Models\SystemSetting::class
        );

        /*
        |--------------------------------------------------------------------------
        | Get Settings
        |--------------------------------------------------------------------------
        */

        $settings = $systemSettingService->get();

        /*
        |--------------------------------------------------------------------------
        | Database Audit Log
        |--------------------------------------------------------------------------
        */

        $systemLogService->viewed(
            resource: $settings,
            module: SystemLogModule::SYSTEM,
            description: 'Viewed system settings.',
            metadata: [
                'settings_id' => $settings->id,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Laravel Application Log
        |--------------------------------------------------------------------------
        */

        Log::info(
            'System settings viewed.',
            [
                'user_id' => request()->user()?->id,
                'settings_id' => $settings->id,
                'ip_address' => request()->ip(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data: new SystemSettingResource(
                $settings
            ),
            message: 'System settings retrieved successfully.',
        );
    }

    /**
     * Update system settings.
     *
     * PUT/PATCH /api/v1/system-settings
     *
     * @throws Throwable
     */
    public function update(
        UpdateSystemSettingRequest $request,
        SystemSettingService $systemSettingService,
        SystemLogService $systemLogService
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'update',
            \App\Models\SystemSetting::class
        );

        /*
        |--------------------------------------------------------------------------
        | Validated Data
        |--------------------------------------------------------------------------
        */

        $data = $request->validated();

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        try {
            $settings = $systemSettingService->update(
                data: $data,
                systemLogService: $systemLogService,
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return ApiResponse::updated(
                data: new SystemSettingResource(
                    $settings
                ),
                message: 'System settings updated successfully.',
            );
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Log Failure
            |--------------------------------------------------------------------------
            */

            $systemSettingService->logFailure(
                exception: $exception,
                systemLogService: $systemLogService,
            );

            /*
            |--------------------------------------------------------------------------
            | Re-throw
            |--------------------------------------------------------------------------
            |
            | Your global exception handler will convert this into
            | the canonical API error response.
            |
            */

            throw $exception;
        }
    }
}
