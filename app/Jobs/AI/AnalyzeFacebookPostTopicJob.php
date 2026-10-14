<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\FacebookPost;
use App\Models\FacebookSyncRun;
use App\Models\SystemSetting;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use App\Services\AI\AIClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AnalyzeFacebookPostTopic implements ShouldQueue
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
     * Maximum number of attempts.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 180;

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new job.
     *
     * IMPORTANT:
     *
     * $postIds are local FacebookPost UUIDs:
     *
     *     facebook_posts.id
     *
     * They are NOT Meta Facebook post IDs:
     *
     *     facebook_posts.facebook_post_id
     *
     * $syncRunId is the local FacebookSyncRun UUID:
     *
     *     facebook_sync_runs.id
     *
     * @param array<int, string> $postIds
     */
    public function __construct(
        public readonly array $postIds,
        public readonly string $syncRunId,
    ) {
        /*
         * Facebook AI has its own dedicated queue.
         */
        $this->onQueue('facebook-ai');
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Middleware
    |--------------------------------------------------------------------------
    */

    /**
     * Prevent overlapping analysis for the same group of posts.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $id): string => trim((string) $id),
                        $this->postIds,
                    ),
                    static fn (string $id): bool => $id !== '',
                ),
            ),
        );

        $lockKey = 'facebook-post-topic-analysis:' . sha1(
            implode('|', $ids),
        );

        return [
            (new WithoutOverlapping($lockKey))
                ->expireAfter(300)
                ->dontRelease(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Handle
    |--------------------------------------------------------------------------
    */

    /**
     * Execute the job.
     */
    public function handle(
        AIClient $aiClient,
        FacebookSyncRunService $syncRunService,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate Job Input
        |--------------------------------------------------------------------------
        */

        $postIds = $this->normalizePostIds();
        $syncRunId = trim($this->syncRunId);

        if ($postIds === []) {
            throw new RuntimeException(
                'No valid Facebook post IDs were provided.',
            );
        }

        if ($syncRunId === '') {
            throw new RuntimeException(
                'Facebook synchronization run ID cannot be empty.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load Sync Run
        |--------------------------------------------------------------------------
        */

        $syncRun = FacebookSyncRun::query()
            ->whereKey($syncRunId)
            ->first();

        if ($syncRun === null) {
            throw new RuntimeException(
                "Facebook synchronization run '{$syncRunId}' was not found.",
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Sync Run State
        |--------------------------------------------------------------------------
        |
        | The root synchronization job should have initialized the
        | pipeline before dispatching this AI job.
        |
        */

        if ($syncRun->status->value !== 'running') {
            Log::warning(
                'Facebook post topic analysis encountered a SyncRun '
                . 'that is not currently running.',
                [
                    'sync_run_id' => $syncRun->id,
                    'sync_run_status' => $syncRun->status->value,
                    'post_count' => count($postIds),
                ],
            );

            /*
             * Do not process an AI job against an already terminal
             * synchronization run.
             */
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Log Job Start
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook post topic analysis job started.',
            [
                'sync_run_id' => $syncRun->id,

                'post_count' =>
                    count($postIds),

                'post_ids' =>
                    $postIds,

                'queue' =>
                    'facebook-ai',

                'job_id' =>
                    $this->job?->getJobId(),

                'attempt' =>
                    $this->attempts(),
            ],
        );

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
        | AI Master Switch
        |--------------------------------------------------------------------------
        */

        if (! $settings->ai_enabled) {
            Log::info(
                'Facebook post topic analysis skipped because AI '
                . 'is disabled.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_count' => count($postIds),
                ],
            );

            /*
             * The AI job was expected by the pipeline, but the
             * current configuration no longer permits execution.
             *
             * Treat the job as completed/skipped so the SyncRun
             * does not remain stuck forever.
             */
            $this->completePipelineStage(
                $syncRunService,
                (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Automatic Analysis Switch
        |--------------------------------------------------------------------------
        */

        if (! $settings->ai_automatic_analysis) {
            Log::info(
                'Facebook post topic analysis skipped because automatic '
                . 'AI analysis is disabled.',
                [
                    'sync_run_id' => $syncRun->id,
                    'post_count' => count($postIds),
                ],
            );

            /*
             * The job was already queued as part of this synchronization
             * pipeline. Therefore it must be resolved as completed/skipped.
             */
            $this->completePipelineStage(
                $syncRunService,
                (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Posts
        |--------------------------------------------------------------------------
        |
        | Only analyze posts that:
        *
        * - exist
        * - contain a message
        * - belong to this synchronization run's Facebook Page
        *
        */

        $posts = FacebookPost::query()
            ->whereIn('id', $postIds)
            ->where(
                'facebook_page_id',
                $syncRun->facebook_page_id,
            )
            ->whereNotNull('message')
            ->where('message', '!=', '')
            ->get([
                'id',
                'message',
                'topic',
                'topic_confidence',
            ]);

        /*
        |--------------------------------------------------------------------------
        | No Analyzable Posts
        |--------------------------------------------------------------------------
        */

        if ($posts->isEmpty()) {
            Log::info(
                'Facebook post topic analysis skipped because no '
                . 'analyzable posts were found.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'requested_post_count' =>
                        count($postIds),
                ],
            );

            /*
             * The AI job has completed its responsibility.
             */
            $this->completePipelineStage(
                $syncRunService,
                (string) $syncRun->id,
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Determine AI Batch Size
        |--------------------------------------------------------------------------
        |
        | AIClient already reads ai_batch_size from SystemSetting.
        *
        * We retrieve the same value here so that this job does not
        * send more records than the configured AI batch size.
        */

        $batchSize = $this->getBatchSize(
            $settings->ai_batch_size,
        );

        $batches = $posts
            ->values()
            ->chunk($batchSize);

        $totalAnalyzed = 0;
        $totalResults = 0;

        /*
        |--------------------------------------------------------------------------
        | Process AI Batches
        |--------------------------------------------------------------------------
        */

        foreach ($batches as $batch) {
            $items = [];

            foreach ($batch as $post) {
                $message = trim(
                    (string) $post->message,
                );

                if ($message === '') {
                    continue;
                }

                $items[] = [
                    'id' =>
                        (string) $post->id,

                    'text' =>
                        $message,
                ];
            }

            if ($items === []) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Call AI Service
            |--------------------------------------------------------------------------
            */

            Log::debug(
                'Sending Facebook posts to AI topic analysis service.',
                [
                    'sync_run_id' =>
                        $syncRun->id,

                    'batch_size' =>
                        count($items),

                    'ai_queue' =>
                        'facebook-ai',
                ],
            );

            /*
             * Send the posts to the AI service.
             *
             * Expected response:
             *
             * [
             *     'results' => [
             *         [
             *             'id' => '...',
             *             'topic' => '...',
             *             'topic_confidence' => 0.95,
             *         ],
             *     ],
             * ]
             */
            $response = $aiClient->analyzeBatch(
                $items,
            );

            $results = $this->extractResults(
                $response,
            );

            $totalResults += count($results);

            /*
            |--------------------------------------------------------------------------
            | Persist Results
            |--------------------------------------------------------------------------
            */

            foreach ($results as $result) {
                if (
                    $this->persistResult($result)
                ) {
                    $totalAnalyzed++;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Pipeline Stage Completed
        |--------------------------------------------------------------------------
        */

        $this->completePipelineStage(
            $syncRunService,
            (string) $syncRun->id,
        );

        /*
        |--------------------------------------------------------------------------
        | Completion Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook post topic analysis completed successfully.',
            [
                'sync_run_id' =>
                    $syncRun->id,

                'requested_post_count' =>
                    count($postIds),

                'analyzable_post_count' =>
                    $posts->count(),

                'ai_result_count' =>
                    $totalResults,

                'analyzed_post_count' =>
                    $totalAnalyzed,

                'job_id' =>
                    $this->job?->getJobId(),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Mark this post-AI queue job as completed and attempt to
     * complete the parent synchronization pipeline.
     *
     * This does NOT blindly mark the SyncRun as completed.
     *
     * FacebookSyncRunService checks all expected pipeline work.
     */
    private function completePipelineStage(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Increment Completed AI Job Counter
        |--------------------------------------------------------------------------
        */

        $syncRunService->incrementPipeline(
            runId: $syncRunId,
            counters: [
                'post_ai_jobs_completed' => 1,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Attempt Full Pipeline Completion
        |--------------------------------------------------------------------------
        */

        $syncRunService->completePipelineIfReady(
            runId: $syncRunId,
        );

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook post AI pipeline stage completed.',
            [
                'sync_run_id' =>
                    $syncRunId,

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
     * Normalize and validate supplied post IDs.
     *
     * @return array<int, string>
     */
    private function normalizePostIds(): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $id): string =>
                            trim((string) $id),
                        $this->postIds,
                    ),
                    static function (string $id): bool {
                        return $id !== '';
                    },
                ),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AI Batch Size
    |--------------------------------------------------------------------------
    */

    /**
     * Get the configured AI batch size.
     */
    private function getBatchSize(
        mixed $configuredSize,
    ): int {
        $batchSize = (int) $configuredSize;

        if ($batchSize < 1) {
            $batchSize = 32;
        }

        /*
         * Keep the same maximum supported by AIClient.
         */
        return min(
            $batchSize,
            500,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AI Response
    |--------------------------------------------------------------------------
    */

    /**
     * Extract AI results from the client response.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function extractResults(
        array $response,
    ): array {
        $results = $response['results'] ?? null;

        if (! is_array($results)) {
            throw new RuntimeException(
                'AI service returned an invalid results payload.',
            );
        }

        return array_values(
            array_filter(
                $results,
                static fn (mixed $result): bool =>
                    is_array($result),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Persist AI Result
    |--------------------------------------------------------------------------
    */

    /**
     * Persist one AI topic result.
     *
     * @param array<string, mixed> $result
     */
    private function persistResult(
        array $result,
    ): bool {
        $postId = $result['id'] ?? null;

        if (
            ! is_string($postId)
            || trim($postId) === ''
        ) {
            Log::warning(
                'Skipping Facebook post AI result because the '
                . 'post ID is missing.',
                [
                    'result' => $result,
                ],
            );

            return false;
        }

        $postId = trim($postId);

        /*
        |--------------------------------------------------------------------------
        | Validate Topic
        |--------------------------------------------------------------------------
        */

        $topic = $result['topic'] ?? null;

        if (
            $topic !== null
            && ! is_string($topic)
        ) {
            Log::warning(
                'Skipping Facebook post AI result because the topic '
                . 'has an invalid type.',
                [
                    'post_id' => $postId,
                    'topic' => $topic,
                ],
            );

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Confidence
        |--------------------------------------------------------------------------
        */

        $confidence =
            $result['topic_confidence'] ?? null;

        if ($confidence !== null) {
            if (
                ! is_numeric($confidence)
                || (float) $confidence < 0.0
                || (float) $confidence > 1.0
            ) {
                Log::warning(
                    'Skipping invalid topic confidence for Facebook post.',
                    [
                        'post_id' =>
                            $postId,

                        'topic_confidence' =>
                            $confidence,
                    ],
                );

                return false;
            }

            $confidence = (float) $confidence;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Topic
        |--------------------------------------------------------------------------
        */

        $topic = is_string($topic)
            ? trim($topic)
            : null;

        /*
         * Empty topic is treated as NULL.
         */
        if ($topic === '') {
            $topic = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Persist
        |--------------------------------------------------------------------------
        */

        $updated = FacebookPost::query()
            ->whereKey($postId)
            ->update([
                'topic' =>
                    $topic,

                'topic_confidence' =>
                    $confidence,

                'updated_at' =>
                    now(),
            ]);

        if ($updated === 0) {
            Log::warning(
                'Facebook post was not found while saving AI topic.',
                [
                    'post_id' =>
                        $postId,
                ],
            );

            return false;
        }

        return true;
    }

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
     * Attempt 3 → permanently fail
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failure
    |--------------------------------------------------------------------------
    */

    /**
     * Handle a permanently failed job.
     *
     * This marks the AI queue stage as failed rather than
     * failing the entire Facebook synchronization run.
     */
    public function failed(
        ?Throwable $exception,
    ): void {
        $syncRunId = trim(
            $this->syncRunId,
        );

        /*
        |--------------------------------------------------------------------------
        | Safe Error Message
        |--------------------------------------------------------------------------
        */

        $errorMessage =
            $this->safeErrorMessage(
                $exception,
            );

        /*
        |--------------------------------------------------------------------------
        | Log Permanent Failure
        |--------------------------------------------------------------------------
        */

        Log::error(
            'Facebook post topic analysis job failed permanently.',
            [
                'sync_run_id' =>
                    $syncRunId !== ''
                        ? $syncRunId
                        : null,

                'post_ids' =>
                    $this->postIds,

                'post_count' =>
                    count($this->postIds),

                'job_id' =>
                    $this->job?->getJobId(),

                'attempts' =>
                    $this->attempts(),

                'exception_class' =>
                    $exception !== null
                        ? $exception::class
                        : null,

                'exception_message' =>
                    $errorMessage,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | No SyncRun
        |--------------------------------------------------------------------------
        */

        if ($syncRunId === '') {
            Log::error(
                'Facebook post topic analysis permanent failure '
                . 'could not update pipeline because sync run ID '
                . 'was empty.',
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Update SyncRun Pipeline
        |--------------------------------------------------------------------------
        */

        try {
            $syncRunService = app(
                FacebookSyncRunService::class,
            );

            /*
            |--------------------------------------------------------------------------
            | Increment Failed AI Job Counter
            |--------------------------------------------------------------------------
            */

            $syncRunService->incrementPipeline(
                runId: $syncRunId,
                counters: [
                    'post_ai_jobs_failed' => 1,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Attempt Full Pipeline Completion
            |--------------------------------------------------------------------------
            |
            | A failed AI job counts as resolved pipeline work.
            *
            * The entire SyncRun can therefore complete once every
            * expected job has either completed or permanently failed.
            */

            $syncRunService->completePipelineIfReady(
                runId: $syncRunId,
            );
        } catch (Throwable $pipelineException) {
            /*
             * Never hide the original queue failure.
             *
             * Pipeline bookkeeping failure is logged separately.
             */
            Log::error(
                'Failed to update Facebook SyncRun pipeline after '
                . 'permanent post AI analysis failure.',
                [
                    'sync_run_id' =>
                        $syncRunId,

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
     * Return a safe error message.
     *
     * Sensitive query parameters such as Meta access tokens
     * must never be persisted in logs.
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