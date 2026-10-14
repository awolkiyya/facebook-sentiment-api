<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Modules\SyncRun\Services\FacebookSyncDispatcherService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncConnectedFacebookPages extends Command
{
    protected $signature = 'facebook:sync-connected-pages';

    protected $description = 'Dispatch automatic synchronization jobs for monitored Facebook Pages.';

    /**
     * Cache key used to track the last automatic Facebook synchronization.
     */
    private const LAST_SYNC_CACHE_KEY = 'facebook:last_automatic_sync_at';

    /**
     * Default synchronization interval in minutes.
     */
    private const DEFAULT_SYNC_INTERVAL = 15;

    public function __construct(
        private readonly FacebookSyncDispatcherService $syncDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Log::info('Facebook automatic synchronization command started.', [
            'command' => $this->getName(),
            'started_at' => now()->toIso8601String(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Load System Settings
        |--------------------------------------------------------------------------
        */

        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            $this->error('System settings could not be loaded.');

            Log::error(
                'Facebook automatic synchronization skipped: system settings not found.',
                [
                    'command' => $this->getName(),
                ]
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Scheduled Tasks
        |--------------------------------------------------------------------------
        */

        if (! $settings->scheduled_tasks_enabled) {
            $this->info(
                'Scheduled tasks are disabled. Facebook synchronization skipped.'
            );

            Log::info(
                'Facebook automatic synchronization skipped: scheduled tasks are disabled.',
                [
                    'scheduled_tasks_enabled' => false,
                ]
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Integration
        |--------------------------------------------------------------------------
        */

        if (! $settings->facebook_sync_enabled) {
            $this->info(
                'Facebook synchronization is disabled. Skipping.'
            );

            Log::info(
                'Facebook automatic synchronization skipped: Facebook synchronization is disabled.',
                [
                    'facebook_sync_enabled' => false,
                ]
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Automatic Synchronization
        |--------------------------------------------------------------------------
        |
        | This controls background synchronization only.
        |
        | Manual synchronization from the frontend/API is not affected by
        | facebook_auto_sync.
        |
        */

        if (! $settings->facebook_auto_sync) {
            $this->info(
                'Automatic Facebook synchronization is disabled. Skipping.'
            );

            Log::info(
                'Facebook automatic synchronization skipped: automatic sync is disabled.',
                [
                    'facebook_auto_sync' => false,
                ]
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Post Synchronization
        |--------------------------------------------------------------------------
        */

        if (! $settings->facebook_posts_enabled) {
            $this->info(
                'Facebook post synchronization is disabled. Skipping.'
            );

            Log::info(
                'Facebook automatic synchronization skipped: post synchronization is disabled.',
                [
                    'facebook_posts_enabled' => false,
                ]
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Synchronization Interval
        |--------------------------------------------------------------------------
        |
        | facebook_sync_interval is stored as minutes.
        |
        | Example:
        |   5  = every 5 minutes
        |   15 = every 15 minutes
        |   30 = every 30 minutes
        |   60 = every 60 minutes
        |
        */

        $interval = (int) $settings->facebook_sync_interval;

        if ($interval <= 0) {
            $interval = self::DEFAULT_SYNC_INTERVAL;

            Log::warning(
                'Invalid Facebook synchronization interval. Falling back to default.',
                [
                    'configured_interval' => $settings->facebook_sync_interval,
                    'default_interval' => self::DEFAULT_SYNC_INTERVAL,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Whether Synchronization Is Due
        |--------------------------------------------------------------------------
        */

        $lastSyncAt = Cache::get(self::LAST_SYNC_CACHE_KEY);

        if ($lastSyncAt !== null) {
            try {
                $lastSync = Carbon::parse($lastSyncAt);
            } catch (Throwable $exception) {
                Log::warning(
                    'Invalid cached Facebook synchronization timestamp. Ignoring it.',
                    [
                        'last_sync_at' => $lastSyncAt,
                        'error' => $exception->getMessage(),
                    ]
                );

                $lastSync = null;
            }

            if (
                $lastSync !== null &&
                $lastSync->diffInMinutes(now()) < $interval
            ) {
                $nextSyncAt = $lastSync->copy()->addMinutes($interval);

                $this->info(
                    sprintf(
                        'Facebook synchronization is not due yet. Next synchronization: %s.',
                        $nextSyncAt->format('Y-m-d H:i:s')
                    )
                );

                Log::debug(
                    'Facebook automatic synchronization is not due yet.',
                    [
                        'last_sync_at' => $lastSync->toIso8601String(),
                        'next_sync_at' => $nextSyncAt->toIso8601String(),
                        'interval_minutes' => $interval,
                    ]
                );

                return self::SUCCESS;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Find Monitored Pages
        |--------------------------------------------------------------------------
        |
        | Only active Facebook Pages with an access token are eligible for
        | automatic synchronization.
        |
        */

        $pages = FacebookPage::query()
            ->where('is_active', true)
            ->whereNotNull('access_token');

        $eligiblePages = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        /*
        |--------------------------------------------------------------------------
        | Dispatch Synchronization Through Dispatcher
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | This command does NOT dispatch SyncFacebookPostsJob directly.
        |
        | The dispatcher:
        |
        |   1. validates the Page
        |   2. creates FacebookSyncRun
        |   3. sets trigger = scheduler
        |   4. sets type = posts
        |   5. selects the correct queue
        |   6. dispatches the actual Queue Job
        |
        */

        $pages->eachById(function (FacebookPage $page) use (
            &$eligiblePages,
            &$dispatched,
            &$skipped,
            &$failed,
        ): void {
            $eligiblePages++;

            try {
                Log::info(
                    'Dispatching scheduled Facebook synchronization.',
                    [
                        'facebook_page_uuid' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'page_name' => $page->name,
                        'trigger' => FacebookSyncTrigger::SCHEDULER->value,
                        'type' => FacebookSyncType::POSTS->value,
                    ]
                );

                $run = $this->syncDispatcher->dispatch(
                    facebookPageId: (string) $page->id,
                    type: FacebookSyncType::POSTS,
                    trigger: FacebookSyncTrigger::SCHEDULER,
                    triggeredByUserId: null,
                    metadata: [
                        'source' => 'scheduler',
                        'command' => $this->getName(),
                        'scheduled_at' => now()->toIso8601String(),
                    ],
                );

                $dispatched++;

                Log::info(
                    'Scheduled Facebook synchronization dispatched successfully.',
                    [
                        'facebook_page_uuid' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'page_name' => $page->name,
                        'sync_run_id' => (string) $run->id,
                        'status' => $run->status->value,
                        'trigger' => $run->trigger->value,
                        'type' => $run->type->value,
                        'queue' => $run->queue,
                    ]
                );
            } catch (Throwable $exception) {
                $failed++;

                /*
                |--------------------------------------------------------------------------
                | Duplicate / Active Run
                |--------------------------------------------------------------------------
                |
                | The dispatcher intentionally prevents more than one queued
                | or running synchronization for the same Facebook Page.
                |
                | A page that already has an active synchronization should
                | therefore be skipped rather than causing the entire scheduler
                | command to fail.
                |
                */

                $skipped++;

                Log::warning(
                    'Scheduled Facebook synchronization was skipped for a Page.',
                    [
                        'facebook_page_uuid' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'page_name' => $page->name,
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]
                );
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Record Automatic Synchronization Time
        |--------------------------------------------------------------------------
        |
        | Record the execution time even when there are currently no monitored
        | pages. This prevents the scheduler from continuously treating the
        | command as due every minute.
        |
        */

        $executedAt = now();

        Cache::forever(
            self::LAST_SYNC_CACHE_KEY,
            $executedAt->toIso8601String()
        );

        /*
        |--------------------------------------------------------------------------
        | Result
        |--------------------------------------------------------------------------
        */

        $this->info(
            sprintf(
                'Facebook synchronization dispatch completed. Eligible pages: %d. Dispatched: %d. Skipped: %d. Failed: %d. Interval: %d minute(s).',
                $eligiblePages,
                $dispatched,
                $skipped,
                $failed,
                $interval
            )
        );

        Log::info(
            'Facebook automatic synchronization command completed.',
            [
                'eligible_pages' => $eligiblePages,
                'dispatched' => $dispatched,
                'skipped' => $skipped,
                'failed' => $failed,
                'interval_minutes' => $interval,
                'executed_at' => $executedAt->toIso8601String(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Command Result
        |--------------------------------------------------------------------------
        |
        | A synchronization failure for one Page should not necessarily make
        | the entire scheduler command fail. The individual Page/run contains
        | its own failure state and can be monitored independently.
        |
        */

        return self::SUCCESS;
    }
}