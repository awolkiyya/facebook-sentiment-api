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

    public int $tries = 3;

    public int $timeout = 180;

    /**
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
     * $postId is the local FacebookPost UUID.
     * $syncRunId is the local FacebookSyncRun UUID.
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
     * Prevent concurrent synchronization for the same local post.
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
        | Load Facebook Post and Page
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

        $syncRun = $this->reloadSyncRun(
            $syncRunId,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . "was not found for page '{$page->id}'.",
            );
        }

        Log::info(
            'Facebook comment synchronization job started.',
            [
                'sync_run_id' => $syncRun->id,
                'sync_run_status' => $this->syncRunStatus($syncRun),
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'facebook_page_internal_id' => $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'queue' => 'facebook-comments',
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'pipeline_operation_key' => $this->pipelineOperationKey(),
            ],
        );

        if ($this->shouldStop($syncRun)) {
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
            $latestRun = $this->reloadSyncRun(
                $syncRunId,
                (string) $page->id,
            );

            if (
                $latestRun !== null
                && $this->isTerminalSyncRun($latestRun)
            ) {
                Log::warning(
                    'Facebook comment synchronization stopped '
                    . 'because the SyncRun became terminal.',
                    [
                        'sync_run_id' => $latestRun->id,
                        'sync_run_status' => $this->syncRunStatus($latestRun),
                        'post_internal_id' => $post->id,
                        'job_id' => $this->job?->getJobId(),
                    ],
                );

                return;
            }

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | Initialize Pipeline
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        if (! $syncRunService->isPipelineInitialized($syncRun)) {
            $syncRun = $syncRunService->initializePipeline(
                runId: (string) $syncRun->id,
            );

            Log::info(
                'Facebook comment synchronization pipeline initialized.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'job_id' => $this->job?->getJobId(),
                ],
            );
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

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Check Synchronization Settings
        |--------------------------------------------------------------------------
        */

        if (! (bool) $settings->facebook_sync_enabled) {
            Log::info(
                'Comment synchronization skipped: Facebook synchronization disabled.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                ],
            );

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        if (! (bool) $settings->facebook_comments_enabled) {
            Log::info(
                'Comment synchronization skipped: comment synchronization disabled.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                ],
            );

            $this->completeCommentSyncPipeline(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
            );

            return;
        }

        if (! $page->is_active) {
            Log::info(
                'Comment synchronization skipped: Facebook page is inactive.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                    'facebook_page_id' => $page->facebook_page_id,
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
        | Final Cancellation Check Before Meta API
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded before Meta synchronization.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Synchronize Comments
        |--------------------------------------------------------------------------
        |
        | FacebookCommentSyncService::sync() must return:
        |
        | [
        |     'comment_ids' => [...local comment UUIDs...],
        |     'statistics' => [
        |         'comments_fetched' => 10,
        |         'comments_created' => 3,
        |         'comments_updated' => 2,
        |     ],
        |     'pages_processed' => 1,
        | ]
        |
        | comment_ids must contain local database UUIDs, not Meta IDs.
        */

        Log::info(
            'Starting Facebook comment synchronization from Meta.',
            [
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'facebook_page_id' => $page->facebook_page_id,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        $syncResult = $commentSyncService->sync($post);

        if (! is_array($syncResult)) {
            throw new RuntimeException(
                'FacebookCommentSyncService::sync() must return an array.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Structured Service Result
        |--------------------------------------------------------------------------
        */

        $commentIds = $this->normalizeCommentIds(
            $syncResult['comment_ids'] ?? [],
        );

        $statistics = is_array($syncResult['statistics'] ?? null)
            ? $syncResult['statistics']
            : [];

        $commentsFetched = max(
            0,
            (int) ($statistics['comments_fetched'] ?? 0),
        );

        $commentsCreated = max(
            0,
            (int) ($statistics['comments_created'] ?? 0),
        );

        $commentsUpdated = max(
            0,
            (int) ($statistics['comments_updated'] ?? 0),
        );

        $pagesProcessed = max(
            0,
            (int) ($syncResult['pages_processed'] ?? 0),
        );

        Log::info(
            'Facebook comment synchronization from Meta completed.',
            [
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'facebook_page_id' => $page->facebook_page_id,
                'comments_fetched' => $commentsFetched,
                'comments_created' => $commentsCreated,
                'comments_updated' => $commentsUpdated,
                'comments_for_analysis' => count($commentIds),
                'pages_processed' => $pagesProcessed,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Record Synchronization Statistics Exactly Once
        |--------------------------------------------------------------------------
        |
        | incrementStatisticsOnce() must atomically check its operation key,
        | update the counters, and persist the operation key.
        */

        $syncRunService->incrementStatisticsOnce(
            runId: (string) $syncRun->id,
            operationKey: $this->commentStatisticsOperationKey(),
            statistics: [
                'comments_fetched' => $commentsFetched,
                'comments_created' => $commentsCreated,
                'comments_updated' => $commentsUpdated,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Reload SyncRun After Meta Synchronization
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded after Meta synchronization.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            Log::warning(
                'Comment synchronization stopped after Meta synchronization '
                . 'because the SyncRun is terminal.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                    'job_id' => $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | No AI Candidates
        |--------------------------------------------------------------------------
        */

        if ($commentIds === []) {
            Log::info(
                'Facebook comments synchronized; no comments require AI analysis.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                    'comments_fetched' => $commentsFetched,
                    'comments_created' => $commentsCreated,
                    'comments_updated' => $commentsUpdated,
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
        | Automatic AI Analysis Settings
        |--------------------------------------------------------------------------
        */

        $automaticAiAnalysis =
            (bool) $settings->ai_enabled
            && (bool) $settings->ai_automatic_analysis;

        if (! $automaticAiAnalysis) {
            Log::info(
                'Facebook comments synchronized, but automatic AI analysis is disabled.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post->id,
                    'facebook_post_id' => $post->facebook_post_id,
                    'comments_for_analysis' => count($commentIds),
                    'ai_enabled' => (bool) $settings->ai_enabled,
                    'ai_automatic_analysis' => (bool) $settings->ai_automatic_analysis,
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
        | Register Expected AI Job Before Dispatch
        |--------------------------------------------------------------------------
        |
        | Register before dispatch to avoid a race where a fast AI worker
        | completes before the parent pipeline records the expected job.
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded before AI dispatch.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

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
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'comment_ai_operation_key' => $this->commentAiRegistrationOperationKey(),
                'counter_applied' => $aiRegistration['applied'] ?? false,
                'comments_for_analysis' => count($commentIds),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Record AI Queue Statistics Exactly Once
        |--------------------------------------------------------------------------
        */

        $syncRunService->incrementStatisticsOnce(
            runId: (string) $syncRun->id,
            operationKey: $this->commentAiStatisticsOperationKey(),
            statistics: [
                'comments_queued_for_ai' => count($commentIds),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Final Cancellation Check Before Dispatch
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded before AI dispatch.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatch AI Analysis
        |--------------------------------------------------------------------------
        |
        | AnalyzeFacebookCommentsJob should be idempotent because a retry
        | may publish a duplicate queue message.
        */

        Log::info(
            'Dispatching Facebook comment AI analysis job.',
            [
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'facebook_page_id' => $page->facebook_page_id,
                'comment_count' => count($commentIds),
                'ai_queue' => 'facebook-ai',
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        AnalyzeFacebookCommentsJob::dispatch(
            commentIds: $commentIds,
            syncRunId: (string) $syncRun->id,
        )->onQueue('facebook-ai');

        Log::info(
            'Facebook comment AI analysis job dispatched.',
            [
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'comment_count' => count($commentIds),
                'ai_queue' => 'facebook-ai',
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Mark Comment Pipeline Job Completed
        |--------------------------------------------------------------------------
        */

        $syncRun = $this->reloadSyncRun(
            (string) $syncRun->id,
            (string) $page->id,
        );

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'could not be reloaded after AI dispatch.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        $this->completeCommentSyncPipeline(
            syncRunService: $syncRunService,
            syncRunId: (string) $syncRun->id,
        );

        Log::info(
            'Facebook comment synchronization pipeline registered successfully.',
            [
                'sync_run_id' => $syncRun->id,
                'post_internal_id' => $post->id,
                'facebook_post_id' => $post->facebook_post_id,
                'comments_fetched' => $commentsFetched,
                'comments_created' => $commentsCreated,
                'comments_updated' => $commentsUpdated,
                'comments_for_analysis' => count($commentIds),
                'comment_ai_jobs_dispatched' => 1,
                'ai_queue' => 'facebook-ai',
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Comment Sync Pipeline
    |--------------------------------------------------------------------------
    */

    private function completeCommentSyncPipeline(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
    ): void {
        $syncRun = FacebookSyncRun::query()
            ->find($syncRunId);

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' "
                . 'was not found while completing comment synchronization.',
            );
        }

        if ($this->shouldStop($syncRun)) {
            return;
        }

        $result = $syncRunService->markPipelineJobCompletedOnce(
            runId: $syncRunId,
            operationKey: $this->pipelineOperationKey(),
            counters: [
                'comment_jobs_completed' => 1,
            ],
        );

        Log::info(
            'Facebook comment synchronization pipeline completion recorded.',
            [
                'sync_run_id' => $syncRunId,
                'pipeline_operation_key' => $this->pipelineOperationKey(),
                'counter_applied' => $result['applied'] ?? false,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        $completedRun = $syncRunService->completePipelineIfReady(
            runId: $syncRunId,
        );

        Log::info(
            'Facebook comment synchronization pipeline completion check finished.',
            [
                'sync_run_id' => $syncRunId,
                'sync_run_status' => $this->syncRunStatus($completedRun),
                'pipeline_completed' => $this->pipelineCompleted($completedRun),
                'job_id' => $this->job?->getJobId(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Operation Keys
    |--------------------------------------------------------------------------
    */

    private function pipelineOperationKey(): string
    {
        return 'comment-sync:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $this->postId);
    }

    private function commentStatisticsOperationKey(): string
    {
        return 'comment-sync-statistics:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $this->postId);
    }

    private function commentAiRegistrationOperationKey(): string
    {
        return 'comment-ai-dispatch:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $this->postId);
    }

    private function commentAiStatisticsOperationKey(): string
    {
        return 'comment-ai-statistics:'
            . $this->syncRunId
            . ':'
            . hash('sha256', $this->postId);
    }

    /*
    |--------------------------------------------------------------------------
    | Comment ID Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize local FacebookComment UUIDs.
     *
     * @return array<int, string>
     */
    private function normalizeCommentIds(mixed $commentIds): array
    {
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

    private function reloadSyncRun(
        string $syncRunId,
        string $pageId,
    ): ?FacebookSyncRun {
        return FacebookSyncRun::query()
            ->whereKey($syncRunId)
            ->where('facebook_page_id', $pageId)
            ->first();
    }

    private function shouldStop(FacebookSyncRun $syncRun): bool
    {
        if (! $this->isTerminalSyncRun($syncRun)) {
            return false;
        }

        Log::warning(
            'Facebook comment synchronization stopped because the SyncRun is terminal.',
            [
                'sync_run_id' => $syncRun->id,
                'sync_run_status' => $this->syncRunStatus($syncRun),
                'post_internal_id' => $this->postId,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        return true;
    }

    private function syncRunStatus(FacebookSyncRun $syncRun): string
    {
        $status = $syncRun->status;

        if ($status instanceof FacebookSyncStatus) {
            return $status->value;
        }

        return (string) $status;
    }

    private function isTerminalSyncRun(FacebookSyncRun $syncRun): bool
    {
        $status = $syncRun->status;

        if ($status instanceof FacebookSyncStatus) {
            return $status->isTerminal();
        }

        return in_array(
            strtolower((string) $status),
            [
                'completed',
                'failed',
                'cancelled',
            ],
            true,
        );
    }

    private function pipelineCompleted(FacebookSyncRun $syncRun): bool
    {
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

    public function failed(?Throwable $exception): void
    {
        $postId = trim($this->postId);
        $syncRunId = trim($this->syncRunId);

        $post = $postId !== ''
            ? FacebookPost::query()
                ->select([
                    'id',
                    'facebook_page_id',
                    'facebook_post_id',
                ])
                ->find($postId)
            : null;

        if ($syncRunId === '') {
            Log::error(
                'Facebook comment synchronization permanently failed without a SyncRun ID.',
                [
                    'post_internal_id' => $postId,
                    'exception_class' => $this->exceptionClass($exception),
                    'exception_message' => $this->safeErrorMessage($exception),
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                ],
            );

            return;
        }

        $query = FacebookSyncRun::query()
            ->whereKey($syncRunId);

        if ($post !== null) {
            $query->where(
                'facebook_page_id',
                $post->facebook_page_id,
            );
        }

        $syncRun = $query->first();

        if ($syncRun === null) {
            Log::error(
                'Facebook comment synchronization permanently failed: SyncRun not found.',
                [
                    'sync_run_id' => $syncRunId,
                    'post_internal_id' => $post?->id ?? $postId,
                    'job_id' => $this->job?->getJobId(),
                    'exception_class' => $this->exceptionClass($exception),
                    'exception_message' => $this->safeErrorMessage($exception),
                ],
            );

            return;
        }

        /*
         * Never overwrite a terminal state, including CANCELLED.
         */
        if ($this->isTerminalSyncRun($syncRun)) {
            Log::warning(
                'Facebook comment synchronization permanently failed '
                . 'after the SyncRun became terminal; no terminal state changed.',
                [
                    'sync_run_id' => $syncRun->id,
                    'sync_run_status' => $this->syncRunStatus($syncRun),
                    'post_internal_id' => $post?->id ?? $postId,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Record Failure in Metadata
        |--------------------------------------------------------------------------
        */

        try {
            $metadata = is_array($syncRun->metadata)
                ? $syncRun->metadata
                : [];

            $failures = is_array($metadata['comment_sync_failures'] ?? null)
                ? $metadata['comment_sync_failures']
                : [];

            $failedPostId = (string) ($post?->id ?? $postId);

            $alreadyRecorded = collect($failures)->contains(
                fn (mixed $failure): bool =>
                    is_array($failure)
                    && (string) ($failure['post_internal_id'] ?? '') === $failedPostId,
            );

            if (! $alreadyRecorded) {
                $failures[] = [
                    'post_internal_id' => $post?->id ?? $postId,
                    'facebook_post_id' => $post?->facebook_post_id,
                    'job_id' => $this->job?->getJobId(),
                    'attempts' => $this->attempts(),
                    'exception_class' => $this->exceptionClass($exception),
                    'error' => $this->safeErrorMessage($exception),
                    'failed_at' => now()->toISOString(),
                ];
            }

            $metadata['comment_sync_failures'] = array_slice(
                $failures,
                -100,
            );

            $syncRun->forceFill([
                'metadata' => $metadata,
            ])->save();
        } catch (Throwable $metadataException) {
            Log::error(
                'Failed to record comment synchronization failure metadata.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post?->id ?? $postId,
                    'exception_class' => $metadataException::class,
                    'exception_message' => $this->safeErrorMessage(
                        $metadataException,
                    ),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Pipeline Failure Exactly Once
        |--------------------------------------------------------------------------
        */

        try {
            /** @var FacebookSyncRunService $syncRunService */
            $syncRunService = app(FacebookSyncRunService::class);

            $result = $syncRunService->markPipelineJobCompletedOnce(
                runId: (string) $syncRun->id,
                operationKey: $this->pipelineOperationKey(),
                counters: [
                    'comment_jobs_failed' => 1,
                ],
            );

            $completedRun = $syncRunService->completePipelineIfReady(
                runId: (string) $syncRun->id,
            );

            Log::warning(
                'Facebook comment synchronization failure recorded in pipeline.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post?->id ?? $postId,
                    'pipeline_operation_key' => $this->pipelineOperationKey(),
                    'counter_applied' => $result['applied'] ?? false,
                    'sync_run_status' => $this->syncRunStatus($completedRun),
                    'pipeline_completed' => $this->pipelineCompleted($completedRun),
                    'attempts' => $this->attempts(),
                    'exception_class' => $this->exceptionClass($exception),
                    'exception_message' => $this->safeErrorMessage($exception),
                ],
            );
        } catch (Throwable $pipelineException) {
            Log::error(
                'Failed to update SyncRun pipeline after permanent comment synchronization failure.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_internal_id' => $post?->id ?? $postId,
                    'pipeline_operation_key' => $this->pipelineOperationKey(),
                    'pipeline_exception_class' => $pipelineException::class,
                    'pipeline_exception_message' => $this->safeErrorMessage(
                        $pipelineException,
                    ),
                    'original_exception_class' => $this->exceptionClass($exception),
                    'original_exception_message' => $this->safeErrorMessage(
                        $exception,
                    ),
                ],
            );
        }

        Log::error(
            'Facebook comment synchronization job failed permanently.',
            [
                'sync_run_id' => $syncRunId,
                'post_internal_id' => $post?->id ?? $postId,
                'facebook_post_id' => $post?->facebook_post_id,
                'facebook_page_id' => $post?->facebook_page_id,
                'pipeline_operation_key' => $this->pipelineOperationKey(),
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
                'exception_class' => $this->exceptionClass($exception),
                'exception_message' => $this->safeErrorMessage($exception),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Helpers
    |--------------------------------------------------------------------------
    */

    private function exceptionClass(?Throwable $exception): ?string
    {
        return $exception === null
            ? null
            : $exception::class;
    }

    /*
    |--------------------------------------------------------------------------
    | Error Sanitization
    |--------------------------------------------------------------------------
    */

    private function safeErrorMessage(?Throwable $exception): ?string
    {
        if ($exception === null) {
            return null;
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            return $this->exceptionClass($exception);
        }

        /*
         * Redact token-bearing query parameters.
         */
        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof|token|api_key|apikey|secret)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
         * Redact bearer credentials.
         */
        $message = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
         * Redact common key/value credential patterns.
         */
        $message = preg_replace(
            '/((?:access_token|appsecret_proof|token|api_key|apikey|password|secret|authorization)\s*[:=]\s*)[^\s&,;]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}
