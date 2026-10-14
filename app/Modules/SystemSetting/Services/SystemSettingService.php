<?php

namespace App\Modules\SystemSetting\Services;

use App\Models\SystemSetting;
use App\Services\SystemLogService;
use App\Supports\SystemLogModule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemSettingService
{
    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    private const CACHE_KEY = 'system_settings';

    private const CACHE_TTL = 3600;

    /*
    |--------------------------------------------------------------------------
    | Get Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Get the application's singleton settings.
     */
    public function get(): SystemSetting
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn (): SystemSetting => SystemSetting::singleton()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Update the application's singleton settings.
     *
     * @throws Throwable
     */
    public function update(
        array $data,
        ?SystemLogService $systemLogService = null
    ): SystemSetting {
        return DB::transaction(
            function () use (
                $data,
                $systemLogService
            ): SystemSetting {
                $settings = SystemSetting::singleton();

                /*
                |--------------------------------------------------------------------------
                | Capture Old Values
                |--------------------------------------------------------------------------
                */

                $oldValues = $settings->only(
                    array_keys($data)
                );

                /*
                |--------------------------------------------------------------------------
                | Fill Settings
                |--------------------------------------------------------------------------
                */

                $settings->fill($data);

                /*
                |--------------------------------------------------------------------------
                | Save Only When Changed
                |--------------------------------------------------------------------------
                */

                if (! $settings->isDirty()) {
                    return $settings;
                }

                /*
                |--------------------------------------------------------------------------
                | Capture Changed Fields Before Refresh
                |--------------------------------------------------------------------------
                */

                $changedFields = array_keys(
                    $settings->getDirty()
                );

                /*
                |--------------------------------------------------------------------------
                | Capture New Values
                |--------------------------------------------------------------------------
                */

                $newValues = $settings->only(
                    $changedFields
                );

                /*
                |--------------------------------------------------------------------------
                | Save
                |--------------------------------------------------------------------------
                */

                $settings->save();

                /*
                |--------------------------------------------------------------------------
                | Refresh Model
                |--------------------------------------------------------------------------
                */

                $settings->refresh();

                /*
                |--------------------------------------------------------------------------
                | Refresh Cache
                |--------------------------------------------------------------------------
                */

                Cache::forget(
                    self::CACHE_KEY
                );

                Cache::put(
                    self::CACHE_KEY,
                    $settings,
                    self::CACHE_TTL
                );

                /*
                |--------------------------------------------------------------------------
                | Laravel Operational Log
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'System settings updated.',
                    [
                        'user_id' => auth()->id(),
                        'settings_id' => $settings->id,
                        'changed_fields' => $changedFields,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Database Audit Log
                |--------------------------------------------------------------------------
                */

                if ($systemLogService !== null) {
                    $systemLogService->log(
                        action: 'SYSTEM_SETTINGS_UPDATED',
                        module: SystemLogModule::SYSTEM,
                        status: SystemLogService::STATUS_SUCCESS,
                        severity: SystemLogService::SEVERITY_WARNING,
                        resource: $settings,
                        description: 'System settings were updated.',
                        oldValues: $oldValues,
                        newValues: $newValues,
                        metadata: [
                            'settings_id' => $settings->id,
                            'changed_fields' => $changedFields,
                        ],
                    );
                }

                return $settings;
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Cache
    |--------------------------------------------------------------------------
    */

    /**
     * Clear the settings cache.
     */
    public function clearCache(): void
    {
        Cache::forget(
            self::CACHE_KEY
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Refresh Cache
    |--------------------------------------------------------------------------
    */

    /**
     * Reload settings and refresh cache.
     */
    public function refresh(): SystemSetting
    {
        $this->clearCache();

        return $this->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Get Individual Setting
    |--------------------------------------------------------------------------
    */

    /**
     * Get a single setting value.
     */
    public function value(
        string $key,
        mixed $default = null
    ): mixed {
        $settings = $this->get();

        $value = $settings->getAttribute(
            $key
        );

        return $value ?? $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Boolean Setting
    |--------------------------------------------------------------------------
    */

    /**
     * Get a boolean setting.
     */
    public function enabled(
        string $key,
        bool $default = false
    ): bool {
        return (bool) $this->value(
            $key,
            $default
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Facebook Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether Facebook synchronization is enabled.
     */
    public function facebookSyncEnabled(): bool
    {
        return $this->enabled(
            'facebook_sync_enabled'
        );
    }

    /**
     * Determine whether Facebook automatic synchronization is enabled.
     */
    public function facebookAutoSyncEnabled(): bool
    {
        return $this->enabled(
            'facebook_auto_sync'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AI Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether AI processing is enabled.
     */
    public function aiEnabled(): bool
    {
        return $this->enabled(
            'ai_enabled'
        );
    }

    /**
     * Determine whether automatic AI analysis is enabled.
     */
    public function aiAutomaticAnalysisEnabled(): bool
    {
        return $this->enabled(
            'ai_automatic_analysis'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether maintenance mode is enabled.
     */
    public function maintenanceModeEnabled(): bool
    {
        return $this->enabled(
            'maintenance_mode'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled Tasks
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether scheduled tasks are enabled.
     */
    public function scheduledTasksEnabled(): bool
    {
        return $this->enabled(
            'scheduled_tasks_enabled'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether application caching is enabled.
     */
    public function cacheEnabled(): bool
    {
        return $this->enabled(
            'cache_enabled',
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Logging
    |--------------------------------------------------------------------------
    */

    /**
     * Log a failed system settings update.
     */
    public function logFailure(
        Throwable $exception,
        ?SystemLogService $systemLogService = null
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Laravel Operational Log
        |--------------------------------------------------------------------------
        */

        Log::error(
            'Failed to update system settings.',
            [
                'user_id' => auth()->id(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Database Audit Log
        |--------------------------------------------------------------------------
        */

        if ($systemLogService !== null) {
            $systemLogService->log(
                action: 'SYSTEM_SETTINGS_UPDATE_FAILED',
                module: SystemLogModule::SYSTEM,
                status: SystemLogService::STATUS_FAILED,
                severity: SystemLogService::SEVERITY_ERROR,
                description: 'Failed to update system settings.',
                metadata: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }
    }
}