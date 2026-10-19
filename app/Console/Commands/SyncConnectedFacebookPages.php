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

    protected $description =
        'Dispatch automatic synchronization jobs for monitored Facebook Pages.';

    /**
     * Cache key used to track the last successful automatic dispatch cycle.
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
        $startedAt = now();

        Log::info('Facebook automatic synchronization command started.', [
            'command' => $this->getName(),
            'started_at' => $startedAt->toIso8601String(),
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
                'Facebook automatic synchronization failed: system settings not found.',
                ['command' => $this->getName()],
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Synchronization Settings
        |--------------------------------------------------------------------------
        */

        $disabledSettings = [
            'scheduled_tasks_enabled' => $settings->scheduled_tasks_enabled,
            'facebook_sync_enabled' => $settings->facebook_sync_enabled,
            'facebook_auto_sync' => $settings->facebook_auto_sync,
            'facebook_posts_enabled' => $settings->facebook_posts_enabled,
        ];

        foreach ($disabledSettings as $setting => $enabled) {
            if (! $enabled) {
                $message = match ($setting) {
                    'scheduled_tasks_enabled' =>
                        'Scheduled tasks are disabled. Facebook synchronization skipped.',
                    'facebook_sync_enabled' =>
                        'Facebook synchronization is disabled. Skipping.',
                    'facebook_auto_sync' =>
                        'Automatic Facebook synchronization is disabled. Skipping.',
                    'facebook_posts_enabled' =>
                        'Facebook post synchronization is disabled. Skipping.',
                    default =>
                        'Facebook synchronization skipped because a required setting is disabled.',
                };

                $this->info($message);

                Log::info(
                    'Facebook automatic synchronization skipped because a required setting is disabled.',
                    [
                        'disabled_setting' => $setting,
                        'settings' => $disabledSettings,
                    ],
                );

                return self::SUCCESS;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Synchronization Interval
        |--------------------------------------------------------------------------
        |
        | facebook_sync_interval is configured in minutes.
        |
        */

        $interval = (int) $settings->facebook_sync_interval;

        if ($interval <= 0) {
            Log::warning(
                'Invalid Facebook synchronization interval. Using the default.',
                [
                    'configured_interval' => $settings->facebook_sync_interval,
                    'default_interval' => self::DEFAULT_SYNC_INTERVAL,
                ],
            );

            $interval = self::DEFAULT_SYNC_INTERVAL;
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
                    ],
                );

                $lastSync = null;
            }

            if (
                $lastSync !== null
                && $lastSync->diffInMinutes(now()) < $interval
            ) {
                $nextSyncAt = $lastSync->copy()->addMinutes($interval);

                $message = sprintf(
                    'Facebook synchronization is not due yet. Next synchronization: %s.',
                    $nextSyncAt->format('Y-m-d H:i:s'),
                );

                $this->info($message);

                Log::debug(
                    'Facebook automatic synchronization is not due yet.',
                    [
                        'last_sync_at' => $lastSync->toIso8601String(),
                        'next_sync_at' => $nextSyncAt->toIso8601String(),
                        'interval_minutes' => $interval,
                    ],
                );

                return self::SUCCESS;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Find Eligible Facebook Pages
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | The database column is access_token_encrypted, not access_token.
        | The model or credential service remains responsible for handling
        | the token value and decryption.
        |
        */

        $pages = FacebookPage::query()
            ->where('is_active', true)
            ->whereNotNull('access_token_encrypted')
            ->orderBy('id');

        $eligiblePages = 0;
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        /*
        |--------------------------------------------------------------------------
        | Dispatch Synchronization Jobs
        |--------------------------------------------------------------------------
        |
        | The dispatcher is responsible for creating the sync run, selecting
        | the queue, and dispatching the appropriate job.
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
                    ],
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
                    ],
                );
            } catch (Throwable $exception) {
                $failed++;
                $skipped++;

                Log::error(
                    'Scheduled Facebook synchronization could not be dispatched.',
                    [
                        'facebook_page_uuid' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'page_name' => $page->name,
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ],
                );
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Record Dispatch Timestamp
        |--------------------------------------------------------------------------
        |
        | Do not advance the automatic synchronization timestamp when eligible
        | pages exist but none could be dispatched. This allows the next
        | scheduler invocation to retry rather than waiting for the full interval.
        |
        | If there are no eligible pages, record the timestamp to avoid querying
        | continuously when the scheduler runs every minute.
        |
        */

        if ($eligiblePages === 0 || $dispatched > 0) {
            $executedAt = now();

            Cache::forever(
                self::LAST_SYNC_CACHE_KEY,
                $executedAt->toIso8601String(),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Report Results
        |--------------------------------------------------------------------------
        */

        $summary = sprintf(
            'Facebook synchronization dispatch completed. Eligible pages: %d. Dispatched: %d. Skipped: %d. Failed: %d. Interval: %d minute(s).',
            $eligiblePages,
            $dispatched,
            $skipped,
            $failed,
            $interval,
        );

        $this->info($summary);

        Log::info(
            'Facebook automatic synchronization command completed.',
            [
                'eligible_pages' => $eligiblePages,
                'dispatched' => $dispatched,
                'skipped' => $skipped,
                'failed' => $failed,
                'interval_minutes' => $interval,
                'started_at' => $startedAt->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ],
        );

        return $failed > 0 && $dispatched === 0 && $eligiblePages > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
