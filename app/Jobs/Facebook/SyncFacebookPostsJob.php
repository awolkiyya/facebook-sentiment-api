<?php

declare(strict_types=1);

namespace App\Jobs\Facebook;

use App\Enums\FacebookSyncStatus;
use App\Jobs\AI\AnalyzeFacebookPostTopic;
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

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Number of attempts before the job is permanently failed.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     *
     * 1800 seconds = 30 minutes.
     */
    public int $timeout = 1800;

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Retry delays in seconds.
     *
     * Attempt 1 -> fail -> wait 10 seconds
     * Attempt 2 -> fail -> wait 30 seconds
     * Attempt 3 -> permanently fail
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Middleware
    |--------------------------------------------------------------------------
    */

    /**
     * Prevent concurrent post synchronization jobs for
     * the same Facebook Page.
     *
     * Different Facebook Pages can synchronize concurrently.
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

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new job instance.
     *
     * IMPORTANT:
     *
     * $facebookPageUuid must always be the local Laravel UUID:
     *
     *     facebook_pages.id
     *
     * It is NOT:
     *
     *     facebook_pages.facebook_page_id
     *
     * $syncRunId is:
     *
     *     facebook_sync_runs.id
     */
    public function __construct(
        public readonly string $facebookPageUuid,
        public readonly string $syncRunId,
    ) {
        $this->onQueue('facebook');
    }

    /*
    |--------------------------------------------------------------------------
    | Handle
    |--------------------------------------------------------------------------
    */

    /**
     * Execute the Facebook post synchronization job.
     *
     * High-level workflow:
     *
     * 1. Validate identifiers.
     * 2. Load Facebook Page.
     * 3. Load and validate SyncRun.
     * 4. Mark SyncRun as RUNNING.
     * 5. Initialize pipeline.
     * 6. Load system settings.
     * 7. Validate synchronization state.
     * 8. Synchronize Facebook posts.
     * 9. Determine downstream work.
     * 10. Register statistics.
     * 11. Dispatch post AI jobs.
     * 12. Dispatch comment synchronization jobs.
     * 13. Complete immediately if no downstream work exists.
     * 14. Otherwise leave SyncRun RUNNING.
     *
     * IMPORTANT:
     *
     * Pipeline initialization happens in the worker, not the
     * controller.
     *
     * The controller creates the SyncRun in QUEUED state.
     *
     * This job performs:
     *
     *     QUEUED -> RUNNING -> pipeline initialized
     *
     * FacebookSyncRunService::initializePipeline() is idempotent,
     * so Laravel queue retries do not reset existing pipeline
     * counters or operation registrations.
     */
    public function handle(
        FacebookPostSyncService $postSyncService,
        FacebookSyncRunService $syncRunService,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Job Input
        |--------------------------------------------------------------------------
        */

        $facebookPageUuid = trim(
            $this->facebookPageUuid,
        );

        $syncRunId = trim(
            $this->syncRunId,
        );

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

        /*
        |--------------------------------------------------------------------------
        | Load Facebook Page
        |--------------------------------------------------------------------------
        |
        | The job receives:
        |
        |     facebook_pages.id
        |
        | Never use:
        |
        |     facebook_pages.facebook_page_id
        |
        */

        $page = FacebookPage::query()
            ->find($facebookPageUuid);

        if ($page === null) {
            throw new RuntimeException(
                "Facebook page '{$facebookPageUuid}' was not found.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Synchronization Run
        |--------------------------------------------------------------------------
        |
        | Always scope the SyncRun to the Facebook Page.
        |
        */

        $syncRun = FacebookSyncRun::query()
            ->whereKey($syncRunId)
            ->where(
                'facebook_page_id',
                $page->id,
            )
            ->first();

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "was not found for page '{$page->id}'.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Terminal Run Protection
        |--------------------------------------------------------------------------
        |
        | A duplicate queue delivery must not restart an already
        | completed or permanently failed synchronization.
        |
        */

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook post synchronization job ignored because '
                . 'the SyncRun is already terminal.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'sync_run_status' =>
                        $this->syncRunStatus($syncRun),

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'queue' =>
                        $this->queue,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark SyncRun Running
        |--------------------------------------------------------------------------
        |
        | FacebookSyncRunService::markRunning() is retry-safe.
        |
        | QUEUED  -> RUNNING
        | RUNNING -> unchanged
        |
        */

        $syncRun = $syncRunService->markRunning(
            runId: (string) $syncRun->id,
            jobId: $this->job?->getJobId(),
        );

        /*
        |--------------------------------------------------------------------------
        | Queue Job ID
        |--------------------------------------------------------------------------
        */

        $queueJobId = $this->job?->getJobId();

        /*
        |--------------------------------------------------------------------------
        | Initialize Pipeline
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | The controller creates the SyncRun as QUEUED.
        |
        | Therefore pipeline initialization MUST NOT happen in
        | the controller.
        |
        | At this point the SyncRun is RUNNING.
        |
        | initializePipeline() is idempotent. If this is a retry and
        | the pipeline was already initialized, existing counters and
        | operation registrations are preserved.
        |
        | For this root job we do not yet know downstream counts.
        | Therefore initialize with zero counters first.
        |
        | The actual expected counters are updated below once the
        | Facebook synchronization result is known.
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

        /*
        |--------------------------------------------------------------------------
        | Job Start Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook post synchronization job started.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'page_internal_id' =>
                    $page->id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'queue' =>
                    $this->queue,

                'job_id' =>
                    $queueJobId,

                'attempt' =>
                    $this->attempts(),

                'sync_run_attempts' =>
                    $syncRun->attempts,

                'status' =>
                    $this->syncRunStatus($syncRun),

                'pipeline_initialized' =>
                    $this->pipelineInitialized($syncRun),

                'pipeline_operation_key' =>
                    $this->rootPipelineOperationKey(),
            ],
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Load System Settings
            |--------------------------------------------------------------------------
            */

            $settings = SystemSetting::query()
                ->first();

            if ($settings === null) {
                throw new RuntimeException(
                    'System settings could not be loaded.',
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Facebook Synchronization Switch
            |--------------------------------------------------------------------------
            */

            if (! (bool) $settings->facebook_sync_enabled) {
                Log::warning(
                    'Facebook synchronization is currently disabled. '
                    . 'Post synchronization will not continue.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'page_internal_id' =>
                            $page->id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'job_id' =>
                            $queueJobId,
                    ],
                );

                /*
                 * This is an intentional configuration stop.
                 *
                 * No downstream work exists.
                 *
                 * Marking the run failed is intentional because
                 * the requested synchronization could not execute.
                 */
                $syncRunService->markFailed(
                    runId: (string) $syncRun->id,
                    error: 'Facebook synchronization is currently disabled.',
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Facebook Page Active Check
            |--------------------------------------------------------------------------
            */

            if (! $page->is_active) {
                throw new RuntimeException(
                    "Facebook Page '{$page->id}' is inactive.",
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Synchronize Facebook Posts
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Starting Facebook post synchronization service.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'job_id' =>
                        $queueJobId,

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            $syncResult = $postSyncService->sync(
                page: $page,
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Synchronization Result
            |--------------------------------------------------------------------------
            */

            if (! is_array($syncResult)) {
                throw new RuntimeException(
                    'Facebook post synchronization service returned an invalid result.',
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Downstream Post IDs
            |--------------------------------------------------------------------------
            */

            $commentSyncPostIds = $this->normalizePostIds(
                $syncResult['comment_sync'] ?? [],
            );

            $topicAnalysisPostIds = $this->normalizePostIds(
                $syncResult['topic_analysis'] ?? [],
            );

            /*
            |--------------------------------------------------------------------------
            | Synchronization Result Logging
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook post synchronization service completed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'posts_for_comment_sync' =>
                        count($commentSyncPostIds),

                    'posts_for_topic_analysis' =>
                        count($topicAnalysisPostIds),

                    'job_id' =>
                        $queueJobId,

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Determine Automatic AI Processing
            |--------------------------------------------------------------------------
            */

            $automaticAiAnalysis =
                (bool) $settings->ai_enabled
                && (bool) $settings->ai_automatic_analysis;

            /*
            |--------------------------------------------------------------------------
            | Calculate Post AI Chunks
            |--------------------------------------------------------------------------
            |
            | One AI queue job processes up to 100 posts.
            |
            | posts_queued_for_ai:
            |
            |     number of POSTS
            |
            | post_ai_jobs_expected:
            |
            |     number of QUEUE JOBS
            |
            */

            $postAiChunks = [];

            if (
                $automaticAiAnalysis
                && $topicAnalysisPostIds !== []
            ) {
                $postAiChunks = array_chunk(
                    $topicAnalysisPostIds,
                    100,
                );
            }

            $postAiJobsExpected = count(
                $postAiChunks,
            );

            /*
            |--------------------------------------------------------------------------
            | Calculate Comment Jobs
            |--------------------------------------------------------------------------
            |
            | One comment synchronization job is created per post.
            |
            */

            $commentJobsExpected = count(
                $commentSyncPostIds,
            );

            /*
            |--------------------------------------------------------------------------
            | Update Pipeline Expected Counters
            |--------------------------------------------------------------------------
            |
            | initializePipeline() has already been called.
            |
            | If this is the first attempt, these counters establish
            | the expected downstream workload.
            |
            | If this is a queue retry, initializePipeline() does NOT
            | reset the existing pipeline.
            |
            | Therefore only increment the expected counters when
            | they have not already been registered.
            */

            $expectedRegistrationKey =
                $this->expectedPipelineOperationKey(
                    $commentJobsExpected,
                    $postAiJobsExpected,
                );

            if (
                ! $this->pipelineOperationExists(
                    $syncRun,
                    $expectedRegistrationKey,
                )
            ) {
                $expectedRegistration =
                    $syncRunService->incrementPipelineOnce(
                        runId: (string) $syncRun->id,
                        operationKey: $expectedRegistrationKey,
                        counters: [
                            'comment_jobs_expected' =>
                                $commentJobsExpected,

                            'post_ai_jobs_expected' =>
                                $postAiJobsExpected,
                        ],
                    );

                Log::info(
                    'Facebook synchronization downstream workload '
                    . 'registration processed.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'comment_jobs_expected' =>
                            $commentJobsExpected,

                        'post_ai_jobs_expected' =>
                            $postAiJobsExpected,

                        'operation_key' =>
                            $expectedRegistrationKey,

                        'operation_applied' =>
                            $expectedRegistration['applied'],
                    ],
                );

                $syncRun = $syncRun->fresh();
            } else {
                Log::info(
                    'Facebook synchronization downstream workload '
                    . 'registration already exists.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'operation_key' =>
                            $expectedRegistrationKey,

                        'comment_jobs_expected' =>
                            $commentJobsExpected,

                        'post_ai_jobs_expected' =>
                            $postAiJobsExpected,
                    ],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Update Post Statistics
            |--------------------------------------------------------------------------
            |
            | Persist statistics returned by FacebookPostSyncService.
            |
            */

            $postStatistics = [];

            foreach (
                [
                    'posts_fetched',
                    'posts_created',
                    'posts_updated',
                ] as $statistic
            ) {
                if (
                    isset($syncResult[$statistic])
                    && is_int($syncResult[$statistic])
                    && $syncResult[$statistic] >= 0
                ) {
                    $postStatistics[$statistic] =
                        $syncResult[$statistic];
                }
            }

            if ($postStatistics !== []) {
                /*
                 * The root job can be retried, so use a deterministic
                 * operation key for each statistic.
                 */
                foreach ($postStatistics as $statistic => $value) {
                    $operationKey =
                        $this->postStatisticOperationKey(
                            $statistic,
                        );

                    $result =
                        $syncRunService->incrementPipelineOnce(
                            runId: (string) $syncRun->id,
                            operationKey: $operationKey,
                            counters: [],
                        );

                    if ($result['applied']) {
                        $syncRun =
                            $syncRunService->incrementStatistics(
                                runId: (string) $syncRun->id,
                                statistics: [
                                    $statistic => $value,
                                ],
                            );
                    }

                    Log::info(
                        'Facebook post synchronization statistic '
                        . 'registration processed.',
                        [
                            'sync_run_id' =>
                                $syncRun->id,

                            'statistic' =>
                                $statistic,

                            'value' =>
                                $value,

                            'operation_key' =>
                                $operationKey,

                            'operation_applied' =>
                                $result['applied'],
                        ],
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh SyncRun Before Downstream Dispatch
            |--------------------------------------------------------------------------
            */

            $syncRun = $syncRun->fresh();

            if ($syncRun === null) {
                throw new RuntimeException(
                    "Facebook synchronization run '{$syncRunId}' "
                    . 'could not be refreshed.',
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Protect Against Terminal State Before Dispatch
            |--------------------------------------------------------------------------
            */

            if ($this->isTerminalSyncRun($syncRun)) {
                Log::warning(
                    'Facebook post synchronization stopped before '
                    . 'downstream dispatch because the SyncRun became terminal.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'status' =>
                            $this->syncRunStatus($syncRun),

                        'page_internal_id' =>
                            $page->id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'job_id' =>
                            $queueJobId,

                        'attempt' =>
                            $this->attempts(),
                    ],
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Dispatch Post AI Jobs
            |--------------------------------------------------------------------------
            */

            $topicAnalysisDispatchedPosts = 0;
            $topicAnalysisJobsDispatched = 0;
            $topicAnalysisJobsSkipped = 0;

            foreach (
                $postAiChunks as $chunkIndex => $postIdChunk
            ) {
                $operationKey =
                    $this->postAiDispatchOperationKey(
                        $postIdChunk,
                        $chunkIndex,
                    );

                /*
                 * Prevent duplicate logical dispatches during
                 * Laravel retries.
                 */
                if (
                    $this->pipelineOperationExists(
                        $syncRun,
                        $operationKey,
                    )
                ) {
                    $topicAnalysisJobsSkipped++;

                    Log::info(
                        'Facebook post AI job dispatch skipped because '
                        . 'the downstream operation was already registered.',
                        [
                            'sync_run_id' =>
                                $syncRun->id,

                            'chunk_index' =>
                                $chunkIndex,

                            'operation_key' =>
                                $operationKey,

                            'post_count' =>
                                count($postIdChunk),

                            'reason' =>
                                'already_registered',
                        ],
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Dispatch AI Job
                |--------------------------------------------------------------------------
                */

                AnalyzeFacebookPostTopic::dispatch(
                    postIds: $postIdChunk,
                    syncRunId: (string) $syncRun->id,
                )->onQueue('facebook-ai');

                /*
                |--------------------------------------------------------------------------
                | Register Dispatch Operation
                |--------------------------------------------------------------------------
                |
                | Record the operation only after queue dispatch
                | succeeds.
                |
                */

                $registration =
                    $syncRunService->incrementPipelineOnce(
                        runId: (string) $syncRun->id,
                        operationKey: $operationKey,
                        counters: [],
                    );

                if ($registration['applied']) {
                    $topicAnalysisJobsDispatched++;

                    $topicAnalysisDispatchedPosts +=
                        count($postIdChunk);

                    $syncRun =
                        $syncRunService->incrementStatistics(
                            runId: (string) $syncRun->id,
                            statistics: [
                                'posts_queued_for_ai' =>
                                    count($postIdChunk),
                            ],
                        );
                } else {
                    $topicAnalysisJobsSkipped++;
                }

                Log::info(
                    'Facebook post AI downstream dispatch processed.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'page_internal_id' =>
                            $page->id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'chunk_index' =>
                            $chunkIndex,

                        'operation_key' =>
                            $operationKey,

                        'post_count' =>
                            count($postIdChunk),

                        'dispatch_registered' =>
                            $registration['applied'],

                        'queue' =>
                            'facebook-ai',
                    ],
                );

                $syncRun = $syncRun->fresh();

                if (
                    $syncRun !== null
                    && $this->isTerminalSyncRun($syncRun)
                ) {
                    Log::warning(
                        'Facebook post synchronization stopped during '
                        . 'post AI dispatch because the SyncRun became terminal.',
                        [
                            'sync_run_id' =>
                                $syncRun->id,

                            'status' =>
                                $this->syncRunStatus($syncRun),

                            'chunk_index' =>
                                $chunkIndex,
                        ],
                    );

                    return;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Post AI Dispatch Summary
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook post topic analysis dispatch stage completed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'posts_queued_for_ai' =>
                        $topicAnalysisDispatchedPosts,

                    'ai_jobs_dispatched' =>
                        $topicAnalysisJobsDispatched,

                    'ai_jobs_already_registered' =>
                        $topicAnalysisJobsSkipped,

                    'configured_batch_size' =>
                        100,

                    'ai_enabled' =>
                        (bool) $settings->ai_enabled,

                    'ai_automatic_analysis' =>
                        (bool) $settings->ai_automatic_analysis,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Dispatch Facebook Comment Synchronization Jobs
            |--------------------------------------------------------------------------
            |
            | Each changed/new post receives one logical comment-sync
            | operation.
            |
            */

            $commentJobsDispatched = 0;
            $commentJobsSkipped = 0;

            foreach ($commentSyncPostIds as $postId) {
                $operationKey =
                    $this->commentDispatchOperationKey(
                        $postId,
                    );

                /*
                 * Prevent duplicate child dispatch during a retry.
                 */
                if (
                    $this->pipelineOperationExists(
                        $syncRun,
                        $operationKey,
                    )
                ) {
                    $commentJobsSkipped++;

                    Log::info(
                        'Facebook comment synchronization job dispatch '
                        . 'skipped because the downstream operation was '
                        . 'already registered.',
                        [
                            'sync_run_id' =>
                                $syncRun->id,

                            'post_internal_id' =>
                                $postId,

                            'operation_key' =>
                                $operationKey,

                            'reason' =>
                                'already_registered',
                        ],
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Dispatch Comment Synchronization Job
                |--------------------------------------------------------------------------
                */

                SyncFacebookCommentsJob::dispatch(
                    postId: $postId,
                    syncRunId: (string) $syncRun->id,
                )->onQueue('facebook-comments');

                /*
                |--------------------------------------------------------------------------
                | Register Dispatch Operation
                |--------------------------------------------------------------------------
                */

                $registration =
                    $syncRunService->incrementPipelineOnce(
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
                    'Facebook comment synchronization downstream '
                    . 'dispatch processed.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'page_internal_id' =>
                            $page->id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'post_internal_id' =>
                            $postId,

                        'operation_key' =>
                            $operationKey,

                        'dispatch_registered' =>
                            $registration['applied'],

                        'queue' =>
                            'facebook-comments',
                    ],
                );

                $syncRun = $syncRun->fresh();

                if (
                    $syncRun !== null
                    && $this->isTerminalSyncRun($syncRun)
                ) {
                    Log::warning(
                        'Facebook post synchronization stopped during '
                        . 'comment dispatch because the SyncRun became terminal.',
                        [
                            'sync_run_id' =>
                                $syncRun->id,

                            'status' =>
                                $this->syncRunStatus($syncRun),

                            'post_internal_id' =>
                                $postId,
                        ],
                    );

                    return;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Comment Queue Logging
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook comment synchronization dispatch stage completed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'comment_jobs_expected' =>
                        $commentJobsExpected,

                    'comment_jobs_dispatched' =>
                        $commentJobsDispatched,

                    'comment_jobs_already_registered' =>
                        $commentJobsSkipped,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Pipeline Summary
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook synchronization downstream pipeline processed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'comment_jobs_expected' =>
                        $commentJobsExpected,

                    'comment_jobs_dispatched_this_attempt' =>
                        $commentJobsDispatched,

                    'comment_jobs_already_registered' =>
                        $commentJobsSkipped,

                    'post_ai_jobs_expected' =>
                        $postAiJobsExpected,

                    'post_ai_jobs_dispatched_this_attempt' =>
                        $topicAnalysisJobsDispatched,

                    'post_ai_jobs_already_registered' =>
                        $topicAnalysisJobsSkipped,

                    'posts_queued_for_ai_this_attempt' =>
                        $topicAnalysisDispatchedPosts,

                    'comment_ai_jobs_expected' =>
                        $this->pipelineCounter(
                            $syncRun,
                            'comment_ai_jobs_expected',
                        ),

                    'pipeline_status' =>
                        'waiting_for_downstream_jobs',
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Complete Immediately When No Downstream Work Exists
            |--------------------------------------------------------------------------
            */

            if (
                $commentJobsExpected === 0
                && $postAiJobsExpected === 0
            ) {
                $syncRun =
                    $syncRunService->completePipelineIfReady(
                        runId: (string) $syncRun->id,
                    );

                Log::info(
                    'Facebook synchronization completed immediately '
                    . 'because no downstream jobs were required.',
                    [
                        'sync_run_id' =>
                            $syncRun->id,

                        'page_internal_id' =>
                            $page->id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'status' =>
                            $this->syncRunStatus($syncRun),

                        'pipeline_completed' =>
                            $this->pipelineCompleted($syncRun),
                    ],
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh SyncRun
            |--------------------------------------------------------------------------
            */

            $syncRun = $syncRun->fresh();

            /*
            |--------------------------------------------------------------------------
            | Final Stage Logging
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook post synchronization stage completed; '
                . 'downstream pipeline remains active.',
                [
                    'sync_run_id' =>
                        $syncRun?->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'posts_for_comment_sync' =>
                        $commentJobsExpected,

                    'comment_jobs_dispatched_this_attempt' =>
                        $commentJobsDispatched,

                    'comment_jobs_already_registered' =>
                        $commentJobsSkipped,

                    'posts_for_topic_analysis' =>
                        count($topicAnalysisPostIds),

                    'post_ai_jobs_expected' =>
                        $postAiJobsExpected,

                    'post_ai_jobs_dispatched_this_attempt' =>
                        $topicAnalysisJobsDispatched,

                    'post_ai_jobs_already_registered' =>
                        $topicAnalysisJobsSkipped,

                    'posts_queued_for_ai_this_attempt' =>
                        $topicAnalysisDispatchedPosts,

                    'comment_ai_jobs_expected' =>
                        $syncRun !== null
                            ? $this->pipelineCounter(
                                $syncRun,
                                'comment_ai_jobs_expected',
                            )
                            : 0,

                    'ai_enabled' =>
                        (bool) $settings->ai_enabled,

                    'ai_automatic_analysis' =>
                        (bool) $settings->ai_automatic_analysis,

                    'attempt' =>
                        $this->attempts(),

                    'status' =>
                        $syncRun?->status,

                    'pipeline_completed' =>
                        $syncRun !== null
                            ? $this->pipelineCompleted($syncRun)
                            : false,
                ],
            );
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Current Attempt Failure
            |--------------------------------------------------------------------------
            |
            | Do not mark the SyncRun permanently failed here.
            |
            | Laravel retries according to:
            |
            |     tries = 3
            |     backoff = [10, 30, 60]
            |
            | The failed() callback handles permanent failure.
            |
            */

            Log::error(
                'Facebook post synchronization attempt failed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $page->id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'queue' =>
                        $this->queue,

                    'job_id' =>
                        $queueJobId,

                    'attempt' =>
                        $this->attempts(),

                    'max_attempts' =>
                        $this->tries,

                    'exception_class' =>
                        $exception::class,

                    'exception_message' =>
                        $this->safeErrorMessage(
                            $exception,
                        ),
                ],
            );

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Operation Keys
    |--------------------------------------------------------------------------
    */

    /**
     * Root operation key.
     *
     * This identifies the post synchronization operation itself.
     */
    private function rootPipelineOperationKey(): string
    {
        return 'post-sync:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                $this->facebookPageUuid,
            );
    }

    /**
     * Deterministic operation key for registering the expected
     * downstream workload.
     *
     * The expected workload is registered exactly once.
     */
    private function expectedPipelineOperationKey(
        int $commentJobsExpected,
        int $postAiJobsExpected,
    ): string {
        return 'pipeline-expected:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                $commentJobsExpected
                . '|'
                . $postAiJobsExpected,
            );
    }

    /**
     * Generate a deterministic operation key for a post-AI
     * downstream dispatch.
     *
     * @param array<int, string> $postIds
     */
    private function postAiDispatchOperationKey(
        array $postIds,
        int $chunkIndex,
    ): string {
        return 'post-ai-dispatch:'
            . $this->syncRunId
            . ':'
            . $chunkIndex
            . ':'
            . hash(
                'sha256',
                implode('|', $postIds),
            );
    }

    /**
     * Generate a deterministic operation key for a comment-sync
     * downstream dispatch.
     */
    private function commentDispatchOperationKey(
        string $postId,
    ): string {
        return 'comment-dispatch:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                trim($postId),
            );
    }

    /**
     * Generate a deterministic operation key for a post statistic.
     */
    private function postStatisticOperationKey(
        string $statistic,
    ): string {
        return 'post-statistic:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                $statistic,
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Inspection
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether a pipeline operation has already been
     * registered.
     */
    private function pipelineOperationExists(
        FacebookSyncRun $syncRun,
        string $operationKey,
    ): bool {
        $pipeline = $this->pipelineMetadata(
            $syncRun,
        );

        $operations = $pipeline['operations'] ?? [];

        if (! is_array($operations)) {
            return false;
        }

        return array_key_exists(
            $operationKey,
            $operations,
        );
    }

    /**
     * Return normalized pipeline metadata.
     *
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

        return is_array($pipeline)
            ? $pipeline
            : [];
    }

    /**
     * Determine whether pipeline initialization has occurred.
     */
    private function pipelineInitialized(
        FacebookSyncRun $syncRun,
    ): bool {
        $pipeline = $this->pipelineMetadata(
            $syncRun,
        );

        return (bool) (
            $pipeline['initialized']
            ?? false
        );
    }

    /**
     * Determine whether the pipeline is marked completed.
     */
    private function pipelineCompleted(
        FacebookSyncRun $syncRun,
    ): bool {
        $pipeline = $this->pipelineMetadata(
            $syncRun,
        );

        return (bool) (
            $pipeline['completed']
            ?? false
        );
    }

    /**
     * Read a pipeline counter safely.
     */
    private function pipelineCounter(
        FacebookSyncRun $syncRun,
        string $counter,
    ): int {
        $pipeline = $this->pipelineMetadata(
            $syncRun,
        );

        $value = $pipeline[$counter] ?? 0;

        if (! is_numeric($value)) {
            return 0;
        }

        return max(
            0,
            (int) $value,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SyncRun Status
    |--------------------------------------------------------------------------
    */

    /**
     * Return SyncRun status as a string.
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
            && property_exists(
                $status,
                'value',
            )
        ) {
            return (string) $status->value;
        }

        return (string) $status;
    }

    /**
     * Determine whether the SyncRun is terminal.
     */
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
    | ID Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize a collection of local FacebookPost UUIDs.
     *
     * Removes:
     *
     * - null values
     * - non-string values
     * - empty strings
     * - whitespace
     * - duplicate IDs
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

        return array_values(
            $normalized,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failure
    |--------------------------------------------------------------------------
    */

    /**
     * Handle permanent failure after all retry attempts.
     *
     * The root post synchronization is the parent/orchestrator job.
     *
     * Therefore a permanent failure here fails the entire SyncRun.
     */
    public function failed(
        ?Throwable $exception,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate SyncRun ID
        |--------------------------------------------------------------------------
        */

        $syncRunId = trim(
            $this->syncRunId,
        );

        if ($syncRunId === '') {
            Log::critical(
                'Facebook post synchronization permanently failed '
                . 'without a SyncRun ID.',
                [
                    'page_internal_id' =>
                        $this->facebookPageUuid,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage(
                            $exception,
                        ),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load SyncRun
        |--------------------------------------------------------------------------
        */

        $syncRun = FacebookSyncRun::query()
            ->find($syncRunId);

        if ($syncRun === null) {
            Log::critical(
                'Facebook post synchronization permanently failed, '
                . 'but the SyncRun could not be found.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'page_internal_id' =>
                        $this->facebookPageUuid,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage(
                            $exception,
                        ),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Do Not Overwrite Existing Terminal State
        |--------------------------------------------------------------------------
        */

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook post synchronization permanent failure '
                . 'callback encountered an already terminal SyncRun.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'status' =>
                        $this->syncRunStatus($syncRun),

                    'page_internal_id' =>
                        $this->facebookPageUuid,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Entire SyncRun Failed
        |--------------------------------------------------------------------------
        */

        try {
            $syncRunService = app(
                FacebookSyncRunService::class,
            );

            $failedRun = $syncRunService->markFailed(
                runId: (string) $syncRun->id,
                error: $this->safeErrorMessage(
                    $exception,
                ),
            );

            Log::error(
                'Facebook post synchronization job failed permanently '
                . 'and the SyncRun was marked failed.',
                [
                    'sync_run_id' =>
                        $failedRun->id,

                    'page_internal_id' =>
                        $this->facebookPageUuid,

                    'facebook_page_id' =>
                        $failedRun->facebookPage?->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage(
                            $exception,
                        ),

                    'status' =>
                        $this->syncRunStatus($failedRun),
                ],
            );
        } catch (Throwable $pipelineException) {
            /*
             * The original queue failure is more important than
             * a secondary bookkeeping exception.
             */
            Log::critical(
                'Facebook post synchronization permanently failed, '
                . 'but updating the SyncRun status also failed.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'page_internal_id' =>
                        $this->facebookPageUuid,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'original_exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'original_exception_message' =>
                        $this->safeErrorMessage(
                            $exception,
                        ),

                    'pipeline_exception_class' =>
                        $pipelineException::class,

                    'pipeline_exception_message' =>
                        $this->safeErrorMessage(
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

    /**
     * Return a safe error message for logs and persistence.
     *
     * Credentials and access tokens must never be stored in
     * facebook_sync_runs.error_message or application logs.
     */
    private function safeErrorMessage(
        ?Throwable $exception,
    ): string {
        if ($exception === null) {
            return 'Facebook synchronization failed without an exception message.';
        }

        $message = trim(
            $exception->getMessage(),
        );

        if ($message === '') {
            return $exception::class;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Sensitive Query Parameters
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Remove Bearer Tokens
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Remove Common API Credentials
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/((?:access_token|appsecret_proof|api_key|apikey|secret|token)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Limit Stored Error Size
        |--------------------------------------------------------------------------
        */

        return mb_substr(
            $message,
            0,
            5000,
        );
    }
}