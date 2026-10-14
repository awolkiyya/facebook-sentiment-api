<?php

declare(strict_types=1);

namespace App\Jobs\Facebook;

use App\Enums\FacebookSyncStatus;
use App\Jobs\AI\AnalyzeFacebookCommentsJob;
use App\Models\FacebookPost;
use App\Models\FacebookSyncRun;
use App\Models\SystemSetting;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use App\Services\Facebook\FacebookCommentSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncFacebookCommentsJob implements ShouldQueue
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
     */
    public int $timeout = 180;

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Retry delays in seconds.
     *
     * Attempt 1 → fail → wait 10 seconds
     * Attempt 2 → fail → wait 30 seconds
     * Attempt 3 → fail → permanently fail
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new job instance.
     *
     * $postId is the local Laravel FacebookPost UUID:
     *
     *     facebook_posts.id
     *
     * It is NOT:
     *
     *     facebook_posts.facebook_post_id
     *
     * $syncRunId is the local Laravel FacebookSyncRun UUID:
     *
     *     facebook_sync_runs.id
     */
    public function __construct(
        public readonly string $postId,
        public readonly string $syncRunId,
    ) {
        $this->onQueue('facebook-comments');
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Middleware
    |--------------------------------------------------------------------------
    */

    /**
     * Prevent two comment synchronization jobs for the same
     * Facebook post from running simultaneously.
     *
     * Different posts can still synchronize concurrently.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "facebook-post-comment-sync:{$this->postId}",
            ))
                ->expireAfter(300)
                ->releaseAfter(30),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Handle
    |--------------------------------------------------------------------------
    */

    /**
     * Execute the Facebook comment synchronization job.
     */
    public function handle(
        FacebookCommentSyncService $commentSyncService,
        FacebookSyncRunService $syncRunService,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Job Input
        |--------------------------------------------------------------------------
        */

        $postId = trim($this->postId);
        $syncRunId = trim($this->syncRunId);

        if ($postId === '') {
            throw new RuntimeException(
                'Facebook post ID cannot be empty.',
            );
        }

        if ($syncRunId === '') {
            throw new RuntimeException(
                'Facebook synchronization run ID cannot be empty.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Facebook Post
        |--------------------------------------------------------------------------
        */

        $post = FacebookPost::query()
            ->with('facebookPage')
            ->find($postId);

        if ($post === null) {
            throw new RuntimeException(
                "Facebook post '{$postId}' was not found.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Parent Facebook Page
        |--------------------------------------------------------------------------
        */

        $page = $post->facebookPage;

        if ($page === null) {
            throw new RuntimeException(
                "Facebook Page for post '{$postId}' was not found.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Parent SyncRun
        |--------------------------------------------------------------------------
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
        | Log Job Start
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook comment synchronization job started.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'sync_run_status' =>
                    $this->syncRunStatus($syncRun),

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_internal_id' =>
                    $page->id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'queue' =>
                    'facebook-comments',

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),

                'pipeline_operation_key' =>
                    $this->pipelineOperationKey(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Terminal SyncRun Protection
        |--------------------------------------------------------------------------
        |
        | COMPLETED, FAILED and CANCELLED runs are terminal.
        |
        | A cancelled run must never continue processing.
        */

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook comment synchronization job ignored because '
                . 'the SyncRun is already terminal.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'sync_run_status' =>
                        $this->syncRunStatus($syncRun),

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

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
        | Ensure SyncRun Is Running
        |--------------------------------------------------------------------------
        */

        try {
            $syncRun = $syncRunService->markRunning(
                runId: (string) $syncRun->id,
                jobId: $this->job?->getJobId(),
            );
        } catch (Throwable $exception) {
            /*
             * This is especially important for cancellation.
             *
             * If the user cancelled the run after we loaded it but
             * before markRunning(), markRunning() will reject the
             * transition from CANCELLED -> RUNNING.
             *
             * Re-load the run and stop cleanly if it was cancelled.
             */
            $latestRun = FacebookSyncRun::query()
                ->find((string) $syncRun->id);

            if (
                $latestRun !== null
                && $latestRun->status === FacebookSyncStatus::CANCELLED
            ) {
                Log::warning(
                    'Facebook comment synchronization job stopped because '
                    . 'the SyncRun was cancelled before it could start.',
                    [
                        'sync_run_id' =>
                            $latestRun->id,

                        'sync_run_status' =>
                            $this->syncRunStatus($latestRun),

                        'post_internal_id' =>
                            $post->id,

                        'facebook_post_id' =>
                            $post->facebook_post_id,

                        'facebook_page_id' =>
                            $page->facebook_page_id,

                        'job_id' =>
                            $this->job?->getJobId(),

                        'attempt' =>
                            $this->attempts(),
                    ],
                );

                return;
            }

            throw $exception;
        }

        Log::info(
            'Facebook comment synchronization SyncRun is running.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'sync_run_status' =>
                    $this->syncRunStatus($syncRun),

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Cancellation Check After RUNNING Transition
        |--------------------------------------------------------------------------
        |
        | The user may cancel the run immediately after markRunning().
        *
        * Always re-read the SyncRun before expensive external work.
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Initialize Pipeline
        |--------------------------------------------------------------------------
        |
        | The controller creates the SyncRun as QUEUED.
        *
        * The worker owns the transition:
        *
        *     QUEUED -> RUNNING
        *
        * and then initializes the asynchronous pipeline.
        */

        if (
            ! $syncRunService->isPipelineInitialized(
                $syncRun,
            )
        ) {
            $syncRun = $syncRunService->initializePipeline(
                runId: (string) $syncRun->id,
            );

            Log::info(
                'Facebook comment synchronization pipeline initialized.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Cancellation Check After Pipeline Initialization
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load System Settings
        |--------------------------------------------------------------------------
        */

        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            throw new RuntimeException(
                'System settings could not be loaded.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Cancellation Check Before Meta API
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Synchronization Switch
        |--------------------------------------------------------------------------
        */

        if (! $settings->facebook_sync_enabled) {
            Log::info(
                'Facebook comment synchronization skipped because '
                . 'Facebook synchronization is disabled.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,
                ],
            );

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Comment Synchronization Switch
        |--------------------------------------------------------------------------
        */

        if (! $settings->facebook_comments_enabled) {
            Log::info(
                'Facebook comment synchronization skipped because '
                . 'Facebook comment synchronization is disabled.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,
                ],
            );

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Page Monitoring Switch
        |--------------------------------------------------------------------------
        */

        if (! $page->is_active) {
            Log::info(
                'Facebook comment synchronization skipped because '
                . 'Page monitoring is disabled.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,
                ],
            );

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Final Cancellation Check Before Meta Synchronization
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Synchronize Comments
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Starting Facebook comment synchronization from Meta.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        $commentIds = $commentSyncService->sync($post);

        /*
        |--------------------------------------------------------------------------
        | Reload SyncRun After External Meta Operation
        |--------------------------------------------------------------------------
        |
        | The Meta request can take some time.
        *
        * The user could cancel the run while the external request
        * was executing.
        *
        * We cannot undo the Meta request, but we can prevent any
        * downstream AI work or pipeline completion after cancellation.
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded after Meta synchronization.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            Log::warning(
                'Facebook comment synchronization stopped after Meta '
                . 'synchronization because the SyncRun was cancelled.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize AI Comment IDs
        |--------------------------------------------------------------------------
        */

        $commentIds = $this->normalizeCommentIds(
            $commentIds,
        );

        Log::info(
            'Facebook comment synchronization from Meta completed.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'comments_for_analysis' =>
                    count($commentIds),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | No AI Candidates
        |--------------------------------------------------------------------------
        */

        if ($commentIds === []) {
            Log::info(
                'Facebook comments synchronized successfully. '
                . 'No comments require AI analysis.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'comments_for_analysis' =>
                        0,
                ],
            );

            /*
             * Re-check cancellation immediately before modifying
             * pipeline state.
             */
            $syncRun = $this->reloadSyncRun(
                $syncRun->id,
                $page->id,
            );

            if ($syncRun === null) {
                throw new RuntimeException(
                    "Facebook synchronization run '{$syncRunId}' "
                    . "could not be reloaded.",
                );
            }

            if ($this->shouldStop($syncRun)) {
                return;
            }

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Determine Whether Automatic AI Analysis Is Enabled
        |--------------------------------------------------------------------------
        */

        $automaticAiAnalysis =
            (bool) $settings->ai_enabled
            && (bool) $settings->ai_automatic_analysis;

        /*
        |--------------------------------------------------------------------------
        | Automatic AI Disabled
        |--------------------------------------------------------------------------
        */

        if (! $automaticAiAnalysis) {
            Log::info(
                'Facebook comments synchronized, but automatic AI '
                . 'analysis was not dispatched.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'comments_for_analysis' =>
                        count($commentIds),

                    'ai_enabled' =>
                        (bool) $settings->ai_enabled,

                    'ai_automatic_analysis' =>
                        (bool) $settings->ai_automatic_analysis,
                ],
            );

            /*
             * Re-check cancellation before marking the logical
             * comment synchronization operation complete.
             */
            $syncRun = $this->reloadSyncRun(
                $syncRun->id,
                $page->id,
            );

            if ($syncRun === null) {
                throw new RuntimeException(
                    "Facebook synchronization run '{$syncRunId}' "
                    . "could not be reloaded.",
                );
            }

            if ($this->shouldStop($syncRun)) {
                return;
            }

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Final Cancellation Check Before AI Dispatch
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatch Comment AI Analysis
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Dispatching Facebook comment AI analysis job.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'comment_count' =>
                    count($commentIds),

                'ai_queue' =>
                    'facebook-ai',

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Dispatch AI Job
        |--------------------------------------------------------------------------
        */

        AnalyzeFacebookCommentsJob::dispatch(
            commentIds: $commentIds,
            syncRunId: (string) $syncRun->id,
        )->onQueue('facebook-ai');

        Log::info(
            'Facebook comment AI analysis job dispatched successfully.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'comment_count' =>
                    count($commentIds),

                'ai_queue' =>
                    'facebook-ai',
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Register Expected Comment AI Job
        |--------------------------------------------------------------------------
        */

        $aiRegistration = $syncRunService->incrementPipelineOnce(
            runId: (string) $syncRun->id,
            operationKey: $this->commentAiRegistrationOperationKey(),
            counters: [
                'comment_ai_jobs_expected' => 1,
            ],
        );

        Log::info(
            'Facebook comment AI pipeline expectation registered.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'comment_ai_operation_key' =>
                    $this->commentAiRegistrationOperationKey(),

                'counter_applied' =>
                    $aiRegistration['applied'],

                'comment_ai_jobs_expected_increment' =>
                    $aiRegistration['applied']
                        ? 1
                        : 0,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Update AI Queue Statistics
        |--------------------------------------------------------------------------
        */

        if ($aiRegistration['applied']) {
            $syncRunService->incrementStatistics(
                runId: (string) $syncRun->id,
                statistics: [
                    'comments_queued_for_ai' =>
                        count($commentIds),
                ],
            );

            Log::info(
                'Facebook comment AI queue statistics updated.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'comments_queued_for_ai' =>
                        count($commentIds),
                ],
            );
        } else {
            Log::info(
                'Facebook comment AI queue statistics were not '
                . 'incremented because the AI registration operation '
                . 'was already recorded.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'comment_ai_operation_key' =>
                        $this->commentAiRegistrationOperationKey(),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Check Cancellation Before Final Pipeline Update
        |--------------------------------------------------------------------------
        |
        | Cancellation could have happened while the AI job was being
        | dispatched/registered.
        *
        * If cancellation already happened, pipeline writes will be
        * rejected by the service because CANCELLED is terminal.
        */

        $syncRun = $this->reloadSyncRun(
            $syncRun->id,
            $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "could not be reloaded.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            Log::warning(
                'Facebook comment synchronization detected cancellation '
                . 'after AI dispatch.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post->id,

                    'facebook_post_id' =>
                        $post->facebook_post_id,

                    'facebook_page_id' =>
                        $page->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Comment Synchronization Job Completed Exactly Once
        |--------------------------------------------------------------------------
        */

        $this->completeCommentSyncPipeline(
            syncRunService: $syncRunService,
            syncRunId: (string) $syncRun->id,
        );

        /*
        |--------------------------------------------------------------------------
        | Final Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook comments synchronized and AI analysis '
            . 'pipeline registered successfully.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'post_internal_id' =>
                    $post->id,

                'facebook_post_id' =>
                    $post->facebook_post_id,

                'facebook_page_id' =>
                    $page->facebook_page_id,

                'comments_for_analysis' =>
                    count($commentIds),

                'comment_ai_jobs_dispatched' =>
                    1,

                'comment_ai_registration_applied' =>
                    $aiRegistration['applied'],

                'ai_queue' =>
                    'facebook-ai',

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Comment Sync Pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * Mark this logical comment synchronization operation as
     * completed exactly once and attempt full pipeline completion.
     */
    private function completeCommentSyncPipeline(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Check SyncRun Before Pipeline Mutation
        |--------------------------------------------------------------------------
        */

        $syncRun = FacebookSyncRun::query()
            ->find($syncRunId);

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "was not found while completing comment synchronization.",
            );
        }

        if ($this->shouldStop($syncRun)) {
            Log::warning(
                'Facebook comment synchronization pipeline completion '
                . 'was skipped because the SyncRun is terminal.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'sync_run_status' =>
                        $this->syncRunStatus($syncRun),

                    'pipeline_operation_key' =>
                        $this->pipelineOperationKey(),

                    'job_id' =>
                        $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Comment Job Completed Exactly Once
        |--------------------------------------------------------------------------
        */

        $result = $syncRunService->markPipelineJobCompletedOnce(
            runId: $syncRunId,
            operationKey: $this->pipelineOperationKey(),
            counters: [
                'comment_jobs_completed' => 1,
            ],
        );

        Log::info(
            'Facebook comment synchronization pipeline completion '
            . 'recorded.',
            [
                'sync_run_id' =>
                    $syncRunId,

                'pipeline_operation_key' =>
                    $this->pipelineOperationKey(),

                'counter_applied' =>
                    $result['applied'],

                'comment_jobs_completed_increment' =>
                    $result['applied']
                        ? 1
                        : 0,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Attempt Full Pipeline Completion
        |--------------------------------------------------------------------------
        */

        $completedRun = $syncRunService->completePipelineIfReady(
            runId: $syncRunId,
        );

        Log::info(
            'Facebook comment synchronization pipeline completion '
            . 'check finished.',
            [
                'sync_run_id' =>
                    $syncRunId,

                'sync_run_status' =>
                    $this->syncRunStatus($completedRun),

                'pipeline_completed' =>
                    $this->pipelineCompleted($completedRun),

                'job_id' =>
                    $this->job?->getJobId(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Operation Keys
    |--------------------------------------------------------------------------
    */

    /**
     * Deterministic operation key for this logical comment-sync job.
     */
    private function pipelineOperationKey(): string
    {
        return 'comment-sync:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                $this->postId,
            );
    }

    /**
     * Deterministic operation key for registering the downstream
     * comment AI job expectation.
     */
    private function commentAiRegistrationOperationKey(): string
    {
        return 'comment-ai-dispatch:'
            . $this->syncRunId
            . ':'
            . hash(
                'sha256',
                $this->postId,
            );
    }

    /*
    |--------------------------------------------------------------------------
    | ID Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize internal FacebookComment UUIDs.
     *
     * @return array<int, string>
     */
    private function normalizeCommentIds(
        mixed $commentIds,
    ): array {
        if (! is_array($commentIds)) {
            return [];
        }

        $normalized = [];

        foreach ($commentIds as $commentId) {
            if (! is_string($commentId)) {
                continue;
            }

            $commentId = trim($commentId);

            if ($commentId === '') {
                continue;
            }

            $normalized[$commentId] = $commentId;
        }

        return array_values($normalized);
    }

    /*
    |--------------------------------------------------------------------------
    | SyncRun Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Reload the SyncRun and ensure it belongs to the expected Page.
     */
    private function reloadSyncRun(
        string $syncRunId,
        string $pageId,
    ): ?FacebookSyncRun {
        return FacebookSyncRun::query()
            ->whereKey($syncRunId)
            ->where(
                'facebook_page_id',
                $pageId,
            )
            ->first();
    }

    /**
     * Determine whether the job must stop processing.
     *
     * CANCELLED is explicitly handled here.
     *
     * COMPLETED and FAILED are also terminal and must not be
     * processed again.
     */
    private function shouldStop(
        FacebookSyncRun $syncRun,
    ): bool {
        if (
            $syncRun->status
            === FacebookSyncStatus::CANCELLED
        ) {
            Log::warning(
                'Facebook comment synchronization job stopping because '
                . 'the SyncRun was cancelled.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'sync_run_status' =>
                        $this->syncRunStatus($syncRun),

                    'post_internal_id' =>
                        $this->postId,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            return true;
        }

        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook comment synchronization job stopping because '
                . 'the SyncRun is terminal.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'sync_run_status' =>
                        $this->syncRunStatus($syncRun),

                    'post_internal_id' =>
                        $this->postId,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            return true;
        }

        return false;
    }

    /**
     * Return the SyncRun status as a string.
     */
    private function syncRunStatus(
        FacebookSyncRun $syncRun,
    ): string {
        $status = $syncRun->status;

        if ($status instanceof FacebookSyncStatus) {
            return $status->value;
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
            (string) $status,
            [
                'completed',
                'failed',
                'cancelled',
            ],
            true,
        );
    }

    /**
     * Safely determine whether the pipeline is marked completed
     * in SyncRun metadata.
     */
    private function pipelineCompleted(
        FacebookSyncRun $syncRun,
    ): bool {
        $metadata = $syncRun->metadata ?? [];

        if (! is_array($metadata)) {
            return false;
        }

        $pipeline = $metadata['pipeline'] ?? [];

        if (! is_array($pipeline)) {
            return false;
        }

        return (bool) ($pipeline['completed'] ?? false);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failure
    |--------------------------------------------------------------------------
    */

    /**
     * Handle permanent failure after all retry attempts.
     *
     * A permanently failed comment synchronization operation
     * increments comment_jobs_failed exactly once.
     */
    public function failed(
        ?Throwable $exception,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Load Post
        |--------------------------------------------------------------------------
        */

        $postId = trim($this->postId);
        $syncRunId = trim($this->syncRunId);

        $post = null;

        if ($postId !== '') {
            $post = FacebookPost::query()
                ->select([
                    'id',
                    'facebook_page_id',
                    'facebook_post_id',
                ])
                ->find($postId);
        }

        /*
        |--------------------------------------------------------------------------
        | Load SyncRun
        |--------------------------------------------------------------------------
        */

        if ($syncRunId === '') {
            Log::error(
                'Facebook comment synchronization permanently failed '
                . 'without a sync run ID.',
                [
                    'post_internal_id' =>
                        $postId,

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage($exception),

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),
                ],
            );

            return;
        }

        $syncRunQuery = FacebookSyncRun::query()
            ->whereKey($syncRunId);

        if ($post !== null) {
            $syncRunQuery->where(
                'facebook_page_id',
                $post->facebook_page_id,
            );
        }

        $syncRun = $syncRunQuery->first();

        if ($syncRun === null) {
            Log::error(
                'Facebook comment synchronization permanently failed '
                . 'because the SyncRun could not be found.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'facebook_post_id' =>
                        $post?->facebook_post_id,

                    'facebook_page_id' =>
                        $post?->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage($exception),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | If User Cancelled the Run
        |--------------------------------------------------------------------------
        |
        | Cancellation is an intentional terminal state.
        *
        * Do NOT turn CANCELLED into FAILED.
        */

        if (
            $syncRun->status
            === FacebookSyncStatus::CANCELLED
        ) {
            Log::warning(
                'Facebook comment synchronization permanently failed '
                . 'after the SyncRun was cancelled. The run remains '
                . 'CANCELLED.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'facebook_post_id' =>
                        $post?->facebook_post_id,

                    'facebook_page_id' =>
                        $post?->facebook_page_id,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),

                    'exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'exception_message' =>
                        $this->safeErrorMessage($exception),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Record Failure in SyncRun Metadata
        |--------------------------------------------------------------------------
        */

        try {
            $metadata = $syncRun->metadata ?? [];

            if (! is_array($metadata)) {
                $metadata = [];
            }

            $commentFailures =
                $metadata['comment_sync_failures'] ?? [];

            if (! is_array($commentFailures)) {
                $commentFailures = [];
            }

            $commentFailures[] = [
                'post_internal_id' =>
                    $post?->id ?? $postId,

                'facebook_post_id' =>
                    $post?->facebook_post_id,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempts' =>
                    $this->attempts(),

                'error' =>
                    $this->safeErrorMessage($exception),

                'failed_at' =>
                    now()->toISOString(),
            ];

            /*
             * Keep only the latest 100 failures.
             */
            $commentFailures = array_slice(
                $commentFailures,
                -100,
            );

            $syncRun->forceFill([
                'metadata' => array_merge(
                    $metadata,
                    [
                        'comment_sync_failures' =>
                            $commentFailures,
                    ],
                ),
            ])->save();

            Log::warning(
                'Facebook comment synchronization failure '
                . 'recorded in SyncRun metadata.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'facebook_post_id' =>
                        $post?->facebook_post_id,

                    'attempts' =>
                        $this->attempts(),
                ],
            );
        } catch (Throwable $metadataException) {
            Log::error(
                'Failed to record Facebook comment synchronization '
                . 'failure in SyncRun metadata.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'metadata_exception_class' =>
                        $metadataException::class,

                    'metadata_exception_message' =>
                        $this->safeErrorMessage(
                            $metadataException,
                        ),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Comment Pipeline Operation Failed Exactly Once
        |--------------------------------------------------------------------------
        */

        try {
            $syncRunService = app(
                FacebookSyncRunService::class,
            );

            $result = $syncRunService->markPipelineJobCompletedOnce(
                runId: (string) $syncRun->id,
                operationKey: $this->pipelineOperationKey(),
                counters: [
                    'comment_jobs_failed' => 1,
                ],
            );

            Log::warning(
                'Facebook comment synchronization permanent failure '
                . 'pipeline result recorded.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'pipeline_operation_key' =>
                        $this->pipelineOperationKey(),

                    'counter_applied' =>
                        $result['applied'],

                    'comment_jobs_failed_increment' =>
                        $result['applied']
                            ? 1
                            : 0,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempts' =>
                        $this->attempts(),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Check Full Pipeline Completion
            |--------------------------------------------------------------------------
            */

            $completedRun = $syncRunService->completePipelineIfReady(
                runId: (string) $syncRun->id,
            );

            Log::info(
                'Facebook comment synchronization permanent failure '
                . 'pipeline completion check finished.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'sync_run_status' =>
                        $this->syncRunStatus($completedRun),

                    'pipeline_completed' =>
                        $this->pipelineCompleted($completedRun),

                    'post_internal_id' =>
                        $post?->id ?? $postId,
                ],
            );
        } catch (Throwable $pipelineException) {
            Log::error(
                'Failed to update Facebook SyncRun pipeline '
                . 'after permanent comment synchronization failure.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_internal_id' =>
                        $post?->id ?? $postId,

                    'pipeline_operation_key' =>
                        $this->pipelineOperationKey(),

                    'pipeline_exception_class' =>
                        $pipelineException::class,

                    'pipeline_exception_message' =>
                        $this->safeErrorMessage(
                            $pipelineException,
                        ),

                    'original_exception_class' =>
                        $exception !== null
                            ? $exception::class
                            : null,

                    'original_exception_message' =>
                        $this->safeErrorMessage($exception),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Permanent Failure Log
        |--------------------------------------------------------------------------
        */

        Log::error(
            'Facebook comment synchronization job failed permanently.',
            [
                'sync_run_id' =>
                    $syncRunId,

                'post_internal_id' =>
                    $post?->id ?? $postId,

                'facebook_post_id' =>
                    $post?->facebook_post_id,

                'facebook_page_id' =>
                    $post?->facebook_page_id,

                'pipeline_operation_key' =>
                    $this->pipelineOperationKey(),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempts' =>
                    $this->attempts(),

                'exception_class' =>
                    $exception !== null
                        ? $exception::class
                        : null,

                'exception_message' =>
                    $this->safeErrorMessage($exception),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Error Sanitization
    |--------------------------------------------------------------------------
    */

    /**
     * Return a safe error message.
     */
    private function safeErrorMessage(
        ?Throwable $exception,
    ): ?string {
        if ($exception === null) {
            return null;
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
        | Remove Common Token-like Query Values
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/((?:access_token|appsecret_proof|token|api_key|apikey|secret)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Limit Error Size
        |--------------------------------------------------------------------------
        */

        return mb_substr(
            $message,
            0,
            5000,
        );
    }
}