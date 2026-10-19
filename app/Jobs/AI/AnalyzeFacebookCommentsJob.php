<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\FacebookComment;
use App\Models\FacebookSyncRun;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use App\Services\AI\AIException;
use App\Services\AI\AIOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeFacebookCommentsJob implements ShouldQueue
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
    public int $timeout = 120;

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
     * Create a new Facebook comment AI analysis job.
     *
     * @param array<int, string> $commentIds
     * @param string $syncRunId
     */
    public function __construct(
        public readonly array $commentIds,
        public readonly string $syncRunId,
    ) {
        /*
         * All Facebook-related AI processing uses the
         * dedicated facebook-ai queue.
         */
        $this->onQueue('facebook-ai');
    }

    /*
    |--------------------------------------------------------------------------
    | Handle
    |--------------------------------------------------------------------------
    */

    /**
     * Execute the Facebook comment AI analysis job.
     *
     * This job is intentionally kept as an orchestration layer.
     *
     * AiOrchestrator owns the actual AI business logic.
     */
    public function handle(
        AiOrchestrator $orchestrator,
        FacebookSyncRunService $syncRunService,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Sync Run ID
        |--------------------------------------------------------------------------
        */

        $syncRunId = trim(
            $this->syncRunId,
        );

        if ($syncRunId === '') {
            throw new AIException(
                'Facebook synchronization run ID cannot be empty.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Comment IDs
        |--------------------------------------------------------------------------
        */

        $commentIds = $this->normalizeCommentIds(
            $this->commentIds,
        );

        if ($commentIds === []) {
            Log::warning(
                'Facebook comment AI analysis skipped because '
                . 'no valid comment IDs were supplied.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            /*
             * The parent comment-sync job should normally never
             * dispatch an empty AI batch.
             *
             * However, if an empty batch reaches the queue, resolve
             * its pipeline stage so the SyncRun cannot remain stuck.
             */
            $this->markPipelineCompleted(
                $syncRunService,
                $syncRunId,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Sync Run
        |--------------------------------------------------------------------------
        */

        $syncRun = FacebookSyncRun::query()
            ->find($syncRunId);

        if ($syncRun === null) {
            throw new AIException(
                "Facebook synchronization run '{$syncRunId}' was not found.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Sync Run State
        |--------------------------------------------------------------------------
        |
        | AI processing belongs to an active synchronization pipeline.
        |
        | If another process has already completed or failed the entire
        | SyncRun, this job must not attempt to modify the terminal run.
        |
        */

        if (
            $syncRun->status === null
            || $syncRun->status->value !== 'running'
        ) {
            Log::warning(
                'Facebook comment AI analysis skipped because '
                . 'the synchronization run is not running.',
                [
                    'sync_run_id' =>
                        (string) $syncRun->id,

                    'status' =>
                        $syncRun->status?->value,

                    'comment_ids' =>
                        $commentIds,

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
        | Load Comments
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | facebook_comments.id is the internal Laravel UUID.
        |
        | The facebook_comments table does NOT contain a
        | facebook_page_id column.
        |
        | A FacebookComment belongs to a FacebookPost through:
        |
        |     facebook_comments.facebook_post_id
        |
        | Therefore comments must be loaded directly by their
        | internal comment IDs supplied to this job.
        |
        */

        Log::debug(
            'Loading Facebook comments for AI analysis.',
            [
                'sync_run_id' =>
                    (string) $syncRun->id,

                'comment_ids' =>
                    $commentIds,

                'comment_count_requested' =>
                    count($commentIds),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        $comments = FacebookComment::query()
            ->whereIn(
                'id',
                $commentIds,
            )
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Log Loaded Comments
        |--------------------------------------------------------------------------
        */

        Log::debug(
            'Facebook comments loaded for AI analysis.',
            [
                'sync_run_id' =>
                    (string) $syncRun->id,

                'comment_count_requested' =>
                    count($commentIds),

                'comment_count_found' =>
                    $comments->count(),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Complete Batch
        |--------------------------------------------------------------------------
        |
        | Never silently process only part of the requested batch.
        |
        */

        if (
            $comments->count()
            !== count($commentIds)
        ) {
            $foundIds = $comments
                ->pluck('id')
                ->map(
                    static fn ($id): string =>
                        (string) $id,
                )
                ->all();

            $missingIds = array_values(
                array_diff(
                    $commentIds,
                    $foundIds,
                ),
            );

            Log::error(
                'Some Facebook comments could not be loaded '
                . 'for AI analysis.',
                [
                    'sync_run_id' =>
                        (string) $syncRun->id,

                    'requested_comment_ids' =>
                        $commentIds,

                    'found_comment_ids' =>
                        $foundIds,

                    'missing_comment_ids' =>
                        $missingIds,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),
                ],
            );

            throw new AIException(
                'Some Facebook comments could not be found for '
                . "sync run '{$syncRunId}': "
                . implode(', ', $missingIds),
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Start Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook comment AI analysis started.',
            [
                'sync_run_id' =>
                    (string) $syncRun->id,

                'facebook_page_id' =>
                    isset($syncRun->facebook_page_id)
                        ? (string) $syncRun->facebook_page_id
                        : null,

                'comments_count' =>
                    $comments->count(),

                'comment_ids' =>
                    $commentIds,

                'queue' =>
                    $this->queue,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Analyze Comments
        |--------------------------------------------------------------------------
        |
        | AiOrchestrator owns:
        |
        | - comment validation
        | - active model resolution
        | - AI service communication
        | - response validation
        | - model-version consistency
        | - sentiment persistence
        | - topic/priority/language processing
        | - latest AI snapshot handling
        |
        */

        try {
            $orchestrator->analyzeComments(
                $comments,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Facebook comment AI analysis failed during '
                . 'orchestrator processing.',
                [
                    'sync_run_id' =>
                        (string) $syncRun->id,

                    'comment_ids' =>
                        $commentIds,

                    'comments_count' =>
                        $comments->count(),

                    'job_id' =>
                        $this->job?->getJobId(),

                    'attempt' =>
                        $this->attempts(),

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

        /*
        |--------------------------------------------------------------------------
        | Mark Pipeline Stage Completed
        |--------------------------------------------------------------------------
        |
        | The AI operation succeeded.
        |
        | Therefore:
        |
        |     comment_ai_jobs_completed += 1
        |
        | Then ask the SyncRun service whether every pipeline stage
        | has reached a terminal state.
        |
        */

        Log::debug(
            'Facebook comment AI analysis succeeded. '
            . 'Updating pipeline completion counter.',
            [
                'sync_run_id' =>
                    (string) $syncRun->id,

                'comments_analyzed' =>
                    $comments->count(),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

        $syncRunService->incrementPipeline(
            runId: (string) $syncRun->id,
            counters: [
                'comment_ai_jobs_completed' => 1,
            ],
        );

        $syncRunService->completePipelineIfReady(
            runId: (string) $syncRun->id,
        );

        /*
        |--------------------------------------------------------------------------
        | Success Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook comment AI analysis completed.',
            [
                'sync_run_id' =>
                    (string) $syncRun->id,

                'facebook_page_id' =>
                    isset($syncRun->facebook_page_id)
                        ? (string) $syncRun->facebook_page_id
                        : null,

                'comments_analyzed' =>
                    $comments->count(),

                'comment_ids' =>
                    $commentIds,

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
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
     * @param mixed $commentIds
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

            $commentId = trim(
                $commentId,
            );

            if ($commentId === '') {
                continue;
            }

            /*
             * Associative-key deduplication prevents the same
             * comment from being processed twice within one job.
             */
            $normalized[$commentId] = $commentId;
        }

        return array_values(
            $normalized,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Completion Helper
    |--------------------------------------------------------------------------
    */

    /**
     * Mark an empty comment-AI batch as completed.
     *
     * This is defensive handling. Normally an empty batch should never
     * be dispatched by SyncFacebookCommentsJob.
     */
    private function markPipelineCompleted(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
    ): void {
        try {
            $syncRunService->incrementPipeline(
                runId: $syncRunId,
                counters: [
                    'comment_ai_jobs_completed' => 1,
                ],
            );

            $syncRunService->completePipelineIfReady(
                runId: $syncRunId,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to update Facebook comment AI pipeline '
                . 'completion state.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'job_id' =>
                        $this->job?->getJobId(),

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
    | Permanent Failure
    |--------------------------------------------------------------------------
    */

    /**
     * Handle permanent failure after all retry attempts.
     *
     * A failed AI batch does not fail the entire SyncRun.
     *
     * Instead:
     *
     *     comment_ai_jobs_failed += 1
     *
     * This allows the overall synchronization pipeline to reach
     * COMPLETED while accurately recording the AI failure.
     */
    public function failed(
        ?Throwable $exception,
    ): void {
        $syncRunId = trim(
            $this->syncRunId,
        );

        /*
        |--------------------------------------------------------------------------
        | Log Permanent Failure
        |--------------------------------------------------------------------------
        */

        Log::error(
            'Facebook comment AI analysis job failed permanently.',
            [
                'sync_run_id' =>
                    $syncRunId !== ''
                        ? $syncRunId
                        : null,

                'comment_ids' =>
                    $this->commentIds,

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

        /*
        |--------------------------------------------------------------------------
        | Validate Sync Run ID
        |--------------------------------------------------------------------------
        */

        if ($syncRunId === '') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Sync Run
        |--------------------------------------------------------------------------
        */

        $syncRun = FacebookSyncRun::query()
            ->find($syncRunId);

        if ($syncRun === null) {
            Log::warning(
                'Facebook comment AI failure could not be '
                . 'associated with a sync run.',
                [
                    'sync_run_id' =>
                        $syncRunId,

                    'job_id' =>
                        $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Record Failure Metadata
        |--------------------------------------------------------------------------
        */

        $this->recordFailureMetadata(
            $syncRun,
            $exception,
        );

        /*
        |--------------------------------------------------------------------------
        | Update Pipeline Failure Counter
        |--------------------------------------------------------------------------
        */

        try {
            $syncRunService = app(
                FacebookSyncRunService::class,
            );

            /*
             * Do not modify a terminal SyncRun.
             */
            if (
                $syncRun->status !== null
                && $syncRun->status->value === 'running'
            ) {
                $syncRunService->incrementPipeline(
                    runId: (string) $syncRun->id,
                    counters: [
                        'comment_ai_jobs_failed' => 1,
                    ],
                );

                /*
                 * This may complete the entire SyncRun if all
                 * other pipeline stages are also terminal.
                 */
                $syncRunService->completePipelineIfReady(
                    runId: (string) $syncRun->id,
                );
            } else {
                Log::warning(
                    'Facebook comment AI failure pipeline counter '
                    . 'was not updated because the sync run is no '
                    . 'longer running.',
                    [
                        'sync_run_id' =>
                            (string) $syncRun->id,

                        'status' =>
                            $syncRun->status?->value,

                        'job_id' =>
                            $this->job?->getJobId(),
                    ],
                );
            }
        } catch (Throwable $pipelineException) {
            /*
             * Pipeline accounting failure must be visible.
             *
             * Do not replace the original AI failure with the
             * accounting exception.
             */
            Log::critical(
                'Failed to update Facebook comment AI pipeline '
                . 'failure state after permanent job failure.',
                [
                    'sync_run_id' =>
                        (string) $syncRun->id,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'original_exception' =>
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
    | Failure Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Record a bounded list of permanent AI failures.
     */
    private function recordFailureMetadata(
        FacebookSyncRun $syncRun,
        ?Throwable $exception,
    ): void {
        $metadata = $syncRun->metadata ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $aiFailures =
            $metadata['comment_ai_failures'] ?? [];

        if (! is_array($aiFailures)) {
            $aiFailures = [];
        }

        $aiFailures[] = [
            'comment_ids' =>
                $this->commentIds,

            'job_id' =>
                $this->job?->getJobId(),

            'attempts' =>
                $this->attempts(),

            'exception_class' =>
                $exception !== null
                    ? $exception::class
                    : null,

            'error' =>
                $this->safeErrorMessage(
                    $exception,
                ),

            'failed_at' =>
                now()->toISOString(),
        ];

        /*
         * Prevent unlimited metadata growth.
         */
        $aiFailures = array_slice(
            $aiFailures,
            -100,
        );

        try {
            $syncRun->forceFill([
                'metadata' => array_merge(
                    $metadata,
                    [
                        'comment_ai_failures' =>
                            $aiFailures,
                    ],
                ),
            ])->save();
        } catch (Throwable $metadataException) {
            /*
             * Metadata persistence must not hide the original
             * queue failure.
             */
            Log::error(
                'Failed to persist Facebook comment AI failure '
                . 'metadata.',
                [
                    'sync_run_id' =>
                        (string) $syncRun->id,

                    'job_id' =>
                        $this->job?->getJobId(),

                    'exception_class' =>
                        $metadataException::class,

                    'exception_message' =>
                        $this->safeErrorMessage(
                            $metadataException,
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
     * Return a safe error message for logs/metadata.
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
        | Remove Sensitive Meta Query Parameters
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Remove Authorization Bearer Tokens
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/(Authorization:\s*Bearer\s+)[^\s,]+/i',
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