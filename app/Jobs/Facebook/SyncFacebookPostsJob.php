<?php

declare(strict_types=1);

namespace App\Jobs\Facebook;

use App\Enums\FacebookSyncStatus;
use App\Models\FacebookPage;
use App\Models\FacebookSyncRun;
use App\Models\SystemSetting;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use App\Services\Facebook\FacebookPostSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncFacebookPostsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum attempts before permanent failure.
     */
    public int $tries = 3;

    /**
     * Maximum execution time: 30 minutes.
     */
    public int $timeout = 1800;

    /**
     * Retry delays in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Prevent concurrent post synchronization for the same page.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "facebook-page-post-sync:{$this->facebookPageUuid}",
            ))
                ->expireAfter(1800)
                ->releaseAfter(30),
        ];
    }

    /**
     * @param string $facebookPageUuid Local facebook_pages.id UUID.
     * @param string $syncRunId        facebook_sync_runs.id.
     */
    public function __construct(
        public readonly string $facebookPageUuid,
        public readonly string $syncRunId,
    ) {
        $this->onQueue('facebook');
    }

    /**
     * Synchronize posts and dispatch comment synchronization jobs.
     *
     * This job does not dispatch post-topic AI analysis.
     */
    public function handle(
        FacebookPostSyncService $postSyncService,
        FacebookSyncRunService $syncRunService,
    ): void {
        $facebookPageUuid = trim($this->facebookPageUuid);
        $syncRunId = trim($this->syncRunId);

        if ($facebookPageUuid === '') {
            throw new RuntimeException(
                'Facebook page UUID cannot be empty.',
            );
        }

        if ($syncRunId === '') {
            throw new RuntimeException(
                'Facebook synchronization run ID cannot be empty.',
            );
        }

        $page = FacebookPage::query()->find($facebookPageUuid);

        if ($page === null) {
            throw new RuntimeException(
                "Facebook page '{$facebookPageUuid}' was not found.",
            );
        }

        $syncRun = FacebookSyncRun::query()
            ->whereKey($syncRunId)
            ->where('facebook_page_id', $page->id)
            ->first();

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "was not found for page '{$page->id}'.",
            );
        }

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook post synchronization ignored because '
                . 'the SyncRun is already terminal.',
                [
                    'sync_run_id' => $syncRun->id,
                    'sync_run_status' => $this->syncRunStatus($syncRun),
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                ],
            );

            return;
        }

        $syncRun = $syncRunService->markRunning(
            runId: (string) $syncRun->id,
            jobId: $this->job?->getJobId(),
        );

        $queueJobId = $this->job?->getJobId();

        /*
         * Initialize the pipeline.
         *
         * Post-AI counters remain zero for compatibility with existing
         * pipeline metadata and completion logic. This job does not
         * dispatch post-AI jobs.
         */
        $syncRun = $syncRunService->initializePipeline(
            runId: (string) $syncRun->id,
            counters: [
                'comment_jobs_expected' => 0,
                'comment_jobs_completed' => 0,
                'comment_jobs_failed' => 0,

                'post_ai_jobs_expected' => 0,
                'post_ai_jobs_completed' => 0,
                'post_ai_jobs_failed' => 0,

                'comment_ai_jobs_expected' => 0,
                'comment_ai_jobs_completed' => 0,
                'comment_ai_jobs_failed' => 0,
            ],
        );

        Log::info(
            'Facebook post synchronization job started.',
            [
                'sync_run_id' => $syncRun->id,
                'page_internal_id' => $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'queue' => $this->queue,
                'job_id' => $queueJobId,
                'attempt' => $this->attempts(),
                'sync_run_attempts' => $syncRun->attempts,
                'status' => $this->syncRunStatus($syncRun),
                'pipeline_initialized' => $this->pipelineInitialized(
                    $syncRun,
                ),
                'pipeline_operation_key' => $this->rootPipelineOperationKey(),
            ],
        );

        try {
            /*
             * Load system settings.
             */
            $settings = SystemSetting::query()->first();

            if ($settings === null) {
                throw new RuntimeException(
                    'System settings could not be loaded.',
                );
            }

            /*
             * Validate the global Facebook synchronization switch.
             */
            if (! (bool) $settings->facebook_sync_enabled) {
                Log::warning(
                    'Facebook synchronization is disabled.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'page_internal_id' => $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'job_id' => $queueJobId,
                    ],
                );

                $syncRunService->markFailed(
                    runId: (string) $syncRun->id,
                    error: 'Facebook synchronization is currently disabled.',
                );

                return;
            }

            /*
             * Validate the page.
             */
            if (! $page->is_active) {
                throw new RuntimeException(
                    "Facebook Page '{$page->id}' is inactive.",
                );
            }

            /*
             * Synchronize Facebook posts.
             */
            Log::info(
                'Starting Facebook post synchronization service.',
                [
                    'sync_run_id' => $syncRun->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'job_id' => $queueJobId,
                    'attempt' => $this->attempts(),
                ],
            );

            $syncResult = $postSyncService->sync(page: $page);

            if (! is_array($syncResult)) {
                throw new RuntimeException(
                    'Facebook post synchronization service returned an invalid result.',
                );
            }

            /*
             * IMPORTANT:
             *
             * FacebookPostSyncService::sync() returns a flat array of
             * local FacebookPost UUIDs that require comment synchronization.
             *
             * It does NOT return:
             *
             * [
             *     'comment_sync' => [...],
             * ]
             *
             * Therefore, normalize the returned array directly.
             *
             * This prevents eligible post IDs from being discarded and
             * the downstream comment job count incorrectly becoming zero.
             *
             * If your service contract is changed later to return an
             * associative result, update this extraction accordingly.
             */
            $commentSyncPostIds = $this->normalizePostIds($syncResult);

            Log::info(
                'Facebook post synchronization service completed.',
                [
                    'sync_run_id' => $syncRun->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'posts_for_comment_sync' => count($commentSyncPostIds),
                    'job_id' => $queueJobId,
                    'attempt' => $this->attempts(),
                ],
            );

            /*
             * Calculate expected comment-sync jobs.
             *
             * One logical comment-sync job is expected per eligible post.
             */
            $commentJobsExpected = count($commentSyncPostIds);

            /*
             * Register the expected workload exactly once.
             */
            $expectedRegistrationKey = $this->expectedPipelineOperationKey(
                $commentJobsExpected,
            );

            if (! $this->pipelineOperationExists(
                $syncRun,
                $expectedRegistrationKey,
            )) {
                $expectedRegistration = $syncRunService->incrementPipelineOnce(
                    runId: (string) $syncRun->id,
                    operationKey: $expectedRegistrationKey,
                    counters: [
                        'comment_jobs_expected' => $commentJobsExpected,
                    ],
                );

                Log::info(
                    'Facebook comment-sync workload registration processed.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'comment_jobs_expected' => $commentJobsExpected,
                        'operation_key' => $expectedRegistrationKey,
                        'operation_applied' => $expectedRegistration['applied'],
                    ],
                );

                $syncRun = $syncRun->fresh();

                if ($syncRun === null) {
                    throw new RuntimeException(
                        "Facebook synchronization run '{$syncRunId}' "
                        . 'could not be refreshed.',
                    );
                }
            } else {
                Log::info(
                    'Facebook comment-sync workload registration already exists.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'operation_key' => $expectedRegistrationKey,
                        'comment_jobs_expected' => $commentJobsExpected,
                    ],
                );
            }

            /*
             * Register post synchronization statistics idempotently.
             *
             * The service's flat-array return contract contains post IDs,
             * not statistics. Statistics are registered only when the
             * service explicitly returns the supported integer keys.
             */
            $postStatistics = [];

            foreach ([
                'posts_fetched',
                'posts_created',
                'posts_updated',
            ] as $statistic) {
                if (
                    isset($syncResult[$statistic])
                    && is_int($syncResult[$statistic])
                    && $syncResult[$statistic] >= 0
                ) {
                    $postStatistics[$statistic] = $syncResult[$statistic];
                }
            }

            foreach ($postStatistics as $statistic => $value) {
                $operationKey = $this->postStatisticOperationKey(
                    $statistic,
                );

                $registration = $syncRunService->incrementPipelineOnce(
                    runId: (string) $syncRun->id,
                    operationKey: $operationKey,
                    counters: [],
                );

                if ($registration['applied']) {
                    $syncRun = $syncRunService->incrementStatistics(
                        runId: (string) $syncRun->id,
                        statistics: [
                            $statistic => $value,
                        ],
                    );
                }

                Log::info(
                    'Facebook post synchronization statistic registration processed.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'statistic' => $statistic,
                        'value' => $value,
                        'operation_key' => $operationKey,
                        'operation_applied' => $registration['applied'],
                    ],
                );
            }

            /*
             * Refresh and protect against a terminal SyncRun.
             */
            $syncRun = $syncRun->fresh();

            if ($syncRun === null) {
                throw new RuntimeException(
                    "Facebook synchronization run '{$syncRunId}' "
                    . 'could not be refreshed.',
                );
            }

            if ($this->isTerminalSyncRun($syncRun)) {
                Log::warning(
                    'Facebook post synchronization stopped before '
                    . 'comment dispatch because the SyncRun is terminal.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'status' => $this->syncRunStatus($syncRun),
                        'page_internal_id' => $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'job_id' => $queueJobId,
                        'attempt' => $this->attempts(),
                    ],
                );

                return;
            }

            /*
             * Dispatch comment synchronization jobs.
             */
            $commentJobsDispatched = 0;
            $commentJobsSkipped = 0;

            foreach ($commentSyncPostIds as $postId) {
                $operationKey = $this->commentDispatchOperationKey(
                    $postId,
                );

                /*
                 * Skip operations already registered by a previous attempt.
                 */
                if ($this->pipelineOperationExists(
                    $syncRun,
                    $operationKey,
                )) {
                    $commentJobsSkipped++;

                    Log::info(
                        'Facebook comment synchronization dispatch skipped '
                        . 'because the operation is already registered.',
                        [
                            'sync_run_id' => $syncRun->id,
                            'post_internal_id' => $postId,
                            'operation_key' => $operationKey,
                            'reason' => 'already_registered',
                        ],
                    );

                    continue;
                }

                /*
                 * Dispatch the comment synchronization job.
                 *
                 * SyncFacebookCommentsJob is in this namespace.
                 */
                SyncFacebookCommentsJob::dispatch(
                    postId: $postId,
                    syncRunId: (string) $syncRun->id,
                )->onQueue('facebook-comments');

                /*
                 * Register the dispatch operation.
                 *
                 * Note: dispatch and registration are separate operations.
                 * A transactional outbox is recommended if guaranteed
                 * recovery from process crashes is required.
                 */
                $registration = $syncRunService->incrementPipelineOnce(
                    runId: (string) $syncRun->id,
                    operationKey: $operationKey,
                    counters: [],
                );

                if ($registration['applied']) {
                    $commentJobsDispatched++;
                } else {
                    $commentJobsSkipped++;
                }

                Log::info(
                    'Facebook comment synchronization downstream dispatch processed.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'page_internal_id' => $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'post_internal_id' => $postId,
                        'operation_key' => $operationKey,
                        'dispatch_registered' => $registration['applied'],
                        'queue' => 'facebook-comments',
                    ],
                );

                $syncRun = $syncRun->fresh();

                if (
                    $syncRun !== null
                    && $this->isTerminalSyncRun($syncRun)
                ) {
                    Log::warning(
                        'Facebook post synchronization stopped during '
                        . 'comment dispatch because the SyncRun is terminal.',
                        [
                            'sync_run_id' => $syncRun->id,
                            'status' => $this->syncRunStatus($syncRun),
                            'post_internal_id' => $postId,
                        ],
                    );

                    return;
                }

                if ($syncRun === null) {
                    throw new RuntimeException(
                        "Facebook synchronization run '{$syncRunId}' "
                        . 'could not be refreshed during comment dispatch.',
                    );
                }
            }

            /*
             * Log comment queue results.
             */
            Log::info(
                'Facebook comment synchronization dispatch stage completed.',
                [
                    'sync_run_id' => $syncRun->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'comment_jobs_expected' => $commentJobsExpected,
                    'comment_jobs_dispatched' => $commentJobsDispatched,
                    'comment_jobs_already_registered' => $commentJobsSkipped,
                ],
            );

            /*
             * Complete immediately if there is no comment-sync work.
             *
             * Post-topic AI is not part of this pipeline's workload.
             */
            if ($commentJobsExpected === 0) {
                $syncRun = $syncRunService->completePipelineIfReady(
                    runId: (string) $syncRun->id,
                );

                Log::info(
                    'Facebook synchronization completed immediately '
                    . 'because no comment-sync jobs were required.',
                    [
                        'sync_run_id' => $syncRun->id,
                        'page_internal_id' => $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'status' => $this->syncRunStatus($syncRun),
                        'pipeline_completed' => $this->pipelineCompleted(
                            $syncRun,
                        ),
                    ],
                );

                return;
            }

            /*
             * Child comment-sync jobs are responsible for advancing
             * pipeline completion.
             */
            $syncRun = $syncRun->fresh();

            Log::info(
                'Facebook post synchronization stage completed; '
                . 'waiting for comment-sync jobs.',
                [
                    'sync_run_id' => $syncRun?->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'comment_jobs_expected' => $commentJobsExpected,
                    'comment_jobs_dispatched_this_attempt' => $commentJobsDispatched,
                    'comment_jobs_already_registered' => $commentJobsSkipped,
                    'comment_ai_jobs_expected' => $syncRun !== null
                        ? $this->pipelineCounter(
                            $syncRun,
                            'comment_ai_jobs_expected',
                        )
                        : 0,
                    'attempt' => $this->attempts(),
                    'status' => $syncRun !== null
                        ? $this->syncRunStatus($syncRun)
                        : null,
                    'pipeline_completed' => $syncRun !== null
                        ? $this->pipelineCompleted($syncRun)
                        : false,
                ],
            );
        } catch (Throwable $exception) {
            Log::error(
                'Facebook post synchronization attempt failed.',
                [
                    'sync_run_id' => $syncRun->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'queue' => $this->queue,
                    'job_id' => $queueJobId,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                ],
            );

            /*
             * Allow Laravel to retry. Permanent failure is handled below.
             */
            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Operation Keys
    |--------------------------------------------------------------------------
    */

    private function rootPipelineOperationKey(): string
    {
        return 'post-sync:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $this->facebookPageUuid);
    }

    private function expectedPipelineOperationKey(
        int $commentJobsExpected,
    ): string {
        return 'pipeline-expected:'
            . $this->syncRunId
            . ':'
            . hash('sha256', (string) $commentJobsExpected);
    }

    private function commentDispatchOperationKey(
        string $postId,
    ): string {
        return 'comment-dispatch:'
            . $this->syncRunId
            . ':'
            . hash('sha256', trim($postId));
    }

    private function postStatisticOperationKey(
        string $statistic,
    ): string {
        return 'post-statistic:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $statistic);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Inspection
    |--------------------------------------------------------------------------
    */

    private function pipelineOperationExists(
        FacebookSyncRun $syncRun,
        string $operationKey,
    ): bool {
        $pipeline = $this->pipelineMetadata($syncRun);
        $operations = $pipeline['operations'] ?? [];

        if (! is_array($operations)) {
            return false;
        }

        return array_key_exists($operationKey, $operations);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineMetadata(
        FacebookSyncRun $syncRun,
    ): array {
        $metadata = $syncRun->metadata ?? [];

        if (! is_array($metadata)) {
            return [];
        }

        $pipeline = $metadata['pipeline'] ?? [];

        return is_array($pipeline) ? $pipeline : [];
    }

    private function pipelineInitialized(
        FacebookSyncRun $syncRun,
    ): bool {
        return (bool) (
            $this->pipelineMetadata($syncRun)['initialized'] ?? false
        );
    }

    private function pipelineCompleted(
        FacebookSyncRun $syncRun,
    ): bool {
        return (bool) (
            $this->pipelineMetadata($syncRun)['completed'] ?? false
        );
    }

    private function pipelineCounter(
        FacebookSyncRun $syncRun,
        string $counter,
    ): int {
        $value = $this->pipelineMetadata($syncRun)[$counter] ?? 0;

        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /*
    |--------------------------------------------------------------------------
    | SyncRun Status
    |--------------------------------------------------------------------------
    */

    private function syncRunStatus(
        FacebookSyncRun $syncRun,
    ): string {
        $status = $syncRun->status;

        if ($status instanceof FacebookSyncStatus) {
            return $status->value;
        }

        if (
            is_object($status)
            && property_exists($status, 'value')
        ) {
            return (string) $status->value;
        }

        return (string) $status;
    }

    private function isTerminalSyncRun(
        FacebookSyncRun $syncRun,
    ): bool {
        $status = $syncRun->status;

        if ($status instanceof FacebookSyncStatus) {
            return $status->isTerminal();
        }

        return in_array(
            $this->syncRunStatus($syncRun),
            [
                FacebookSyncStatus::COMPLETED->value,
                FacebookSyncStatus::FAILED->value,
            ],
            true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Post ID Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize local FacebookPost UUIDs.
     *
     * Accepts the flat array returned by FacebookPostSyncService.
     *
     * @param mixed $postIds
     * @return array<int, string>
     */
    private function normalizePostIds(
        mixed $postIds,
    ): array {
        if (! is_array($postIds)) {
            return [];
        }

        $normalized = [];

        foreach ($postIds as $postId) {
            if (! is_string($postId)) {
                continue;
            }

            $postId = trim($postId);

            if ($postId === '') {
                continue;
            }

            $normalized[$postId] = $postId;
        }

        return array_values($normalized);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failure
    |--------------------------------------------------------------------------
    */

    public function failed(
        ?Throwable $exception,
    ): void {
        $syncRunId = trim($this->syncRunId);

        if ($syncRunId === '') {
            Log::critical(
                'Facebook post synchronization permanently failed '
                . 'without a SyncRun ID.',
                [
                    'page_internal_id' => $this->facebookPageUuid,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                    'exception_class' => $exception !== null
                        ? $exception::class
                        : null,
                    'exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                ],
            );

            return;
        }

        $syncRun = FacebookSyncRun::query()->find($syncRunId);

        if ($syncRun === null) {
            Log::critical(
                'Facebook post synchronization permanently failed, '
                . 'but the SyncRun could not be found.',
                [
                    'sync_run_id' => $syncRunId,
                    'page_internal_id' => $this->facebookPageUuid,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                    'exception_class' => $exception !== null
                        ? $exception::class
                        : null,
                    'exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                ],
            );

            return;
        }

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook post synchronization failure callback '
                . 'encountered an already terminal SyncRun.',
                [
                    'sync_run_id' => $syncRun->id,
                    'status' => $this->syncRunStatus($syncRun),
                    'page_internal_id' => $this->facebookPageUuid,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                ],
            );

            return;
        }

        try {
            $syncRunService = app(FacebookSyncRunService::class);

            $failedRun = $syncRunService->markFailed(
                runId: (string) $syncRun->id,
                error: $this->safeErrorMessage($exception),
            );

            Log::error(
                'Facebook post synchronization job failed permanently '
                . 'and the SyncRun was marked failed.',
                [
                    'sync_run_id' => $failedRun->id,
                    'page_internal_id' => $this->facebookPageUuid,
                    'facebook_page_id' => $failedRun->facebookPage?->facebook_page_id,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                    'exception_class' => $exception !== null
                        ? $exception::class
                        : null,
                    'exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                    'status' => $this->syncRunStatus($failedRun),
                ],
            );
        } catch (Throwable $pipelineException) {
            Log::critical(
                'Facebook post synchronization permanently failed, '
                . 'but updating the SyncRun status also failed.',
                [
                    'sync_run_id' => $syncRun->id,
                    'page_internal_id' => $this->facebookPageUuid,
                    'job_id' => $this->job?->getJobId(),
                    'original_exception_class' => $exception !== null
                        ? $exception::class
                        : null,
                    'original_exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                    'pipeline_exception_class' => $pipelineException::class,
                    'pipeline_exception_message' => $this->safeErrorMessage(
                        $pipelineException,
                    ),
                ],
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Error Sanitization
    |--------------------------------------------------------------------------
    */

    private function safeErrorMessage(
        ?Throwable $exception,
    ): string {
        if ($exception === null) {
            return 'Facebook synchronization failed without an exception message.';
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            return $exception::class;
        }

        /*
         * Remove sensitive query parameters.
         */
        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
         * Remove bearer tokens.
         */
        $message = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
         * Remove common API credentials.
         */
        $message = preg_replace(
            '/((?:access_token|appsecret_proof|api_key|apikey|secret|token)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}
