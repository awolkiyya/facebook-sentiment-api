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

    private const MAX_FAILURE_HISTORY = 100;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param array<int, string> $commentIds Local FacebookComment UUIDs.
     */
    public function __construct(
        public readonly array $commentIds,
        public readonly string $syncRunId,
    ) {
        $this->onQueue('facebook-ai');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        AIOrchestrator $orchestrator,
        FacebookSyncRunService $syncRunService,
    ): void {
        $syncRunId = trim($this->syncRunId);

        if ($syncRunId === '') {
            throw new AIException(
                'Facebook synchronization run ID cannot be empty.',
            );
        }

        $commentIds = $this->normalizeCommentIds($this->commentIds);

        $syncRun = FacebookSyncRun::query()->find($syncRunId);

        if ($syncRun === null) {
            throw new AIException(
                "Facebook synchronization run '{$syncRunId}' was not found.",
            );
        }

        if (! $this->isRunRunning($syncRun)) {
            Log::warning(
                'Facebook comment AI analysis skipped because the sync run is not running.',
                [
                    'sync_run_id' => (string) $syncRun->id,
                    'status' => $this->statusValue($syncRun),
                    'comment_count' => count($commentIds),
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                ],
            );

            return;
        }

        /*
         * Empty batches should not normally be dispatched.
         * Account for the pipeline job so the pipeline cannot wait
         * indefinitely for a job that has no work to perform.
         */
        if ($commentIds === []) {
            Log::warning(
                'Facebook comment AI analysis received an empty batch.',
                [
                    'sync_run_id' => $syncRunId,
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                ],
            );

            $this->recordPipelineOutcomeOnce(
                syncRunService: $syncRunService,
                syncRunId: $syncRunId,
                outcome: 'completed',
                commentIds: [],
            );

            $syncRunService->completePipelineIfReady(
                runId: $syncRunId,
            );

            return;
        }

        Log::debug(
            'Loading Facebook comments for AI analysis.',
            [
                'sync_run_id' => $syncRunId,
                'comment_count_requested' => count($commentIds),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        $comments = FacebookComment::query()
            ->whereIn('id', $commentIds)
            ->get();

        if ($comments->count() !== count($commentIds)) {
            $foundIds = $comments
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $missingIds = array_values(
                array_diff($commentIds, $foundIds),
            );

            Log::error(
                'Some Facebook comments could not be loaded for AI analysis.',
                [
                    'sync_run_id' => $syncRunId,
                    'requested_count' => count($commentIds),
                    'found_count' => $comments->count(),
                    'missing_comment_ids' => $missingIds,
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                ],
            );

            throw new AIException(
                'Some Facebook comments could not be found for '
                . "sync run '{$syncRunId}'. Missing count: "
                . count($missingIds),
            );
        }

        Log::info(
            'Facebook comment AI analysis started.',
            [
                'sync_run_id' => $syncRunId,
                'facebook_page_id' => $syncRun->facebook_page_id !== null
                    ? (string) $syncRun->facebook_page_id
                    : null,
                'comments_count' => $comments->count(),
                'queue' => $this->queue,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );

        /*
         * AIOrchestrator remains responsible for inference, response
         * validation, model versioning, and sentiment persistence.
         *
         * If analysis throws, Laravel may retry this job. Do not
         * record a permanent failure here; failed() handles that
         * after retries are exhausted.
         */
        try {
            $orchestrator->analyzeComments($comments);
        } catch (Throwable $exception) {
            Log::error(
                'Facebook comment AI analysis failed during orchestrator processing.',
                [
                    'sync_run_id' => $syncRunId,
                    'comment_count' => $comments->count(),
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeErrorMessage($exception),
                ],
            );

            throw $exception;
        }

        /*
         * Record API-facing AI statistics separately from pipeline
         * counters. Idempotency prevents duplicate counting if the
         * same batch is executed more than once.
         *
         * Record statistics before the pipeline completion counter,
         * because the final pipeline counter may complete the run.
         */
        $this->recordAiStatisticsOnce(
            syncRunService: $syncRunService,
            syncRunId: $syncRunId,
            outcome: 'completed',
            commentIds: $commentIds,
        );

        $this->recordPipelineOutcomeOnce(
            syncRunService: $syncRunService,
            syncRunId: $syncRunId,
            outcome: 'completed',
            commentIds: $commentIds,
        );

        $syncRunService->completePipelineIfReady(
            runId: $syncRunId,
        );

        Log::info(
            'Facebook comment AI analysis completed.',
            [
                'sync_run_id' => $syncRunId,
                'comments_analyzed' => $comments->count(),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ],
        );
    }

    /**
     * Laravel calls this after the job exhausts its retries.
     */
    public function failed(?Throwable $exception): void
    {
        $syncRunId = trim($this->syncRunId);
        $commentIds = $this->normalizeCommentIds($this->commentIds);

        Log::error(
            'Facebook comment AI analysis job failed permanently.',
            [
                'sync_run_id' => $syncRunId !== '' ? $syncRunId : null,
                'comment_count' => count($commentIds),
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
                'exception_class' => $exception !== null
                    ? $exception::class
                    : null,
                'exception_message' => $this->safeErrorMessage($exception),
            ],
        );

        if ($syncRunId === '') {
            return;
        }

        $syncRun = FacebookSyncRun::query()->find($syncRunId);

        if ($syncRun === null) {
            Log::warning(
                'Cannot record permanent comment AI failure because the sync run was not found.',
                [
                    'sync_run_id' => $syncRunId,
                    'job_id' => $this->job?->getJobId(),
                ],
            );

            return;
        }

        /*
         * Persist failure details separately from counters.
         * A metadata failure must not prevent pipeline accounting.
         */
        $this->recordFailureMetadata(
            syncRun: $syncRun,
            exception: $exception,
            commentIds: $commentIds,
        );

        if (! $this->isRunRunning($syncRun)) {
            Log::warning(
                'Permanent comment AI failure was not added to statistics or pipeline counters because the sync run is no longer running.',
                [
                    'sync_run_id' => (string) $syncRun->id,
                    'status' => $this->statusValue($syncRun),
                    'job_id' => $this->job?->getJobId(),
                ],
            );

            return;
        }

        try {
            /** @var FacebookSyncRunService $syncRunService */
            $syncRunService = app(FacebookSyncRunService::class);

            /*
             * Record the API-facing failure statistic before recording
             * the pipeline counter, which may complete the run.
             */
            $this->recordAiStatisticsOnce(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
                outcome: 'failed',
                commentIds: $commentIds,
            );

            $this->recordPipelineOutcomeOnce(
                syncRunService: $syncRunService,
                syncRunId: (string) $syncRun->id,
                outcome: 'failed',
                commentIds: $commentIds,
            );

            $syncRunService->completePipelineIfReady(
                runId: (string) $syncRun->id,
            );
        } catch (Throwable $pipelineException) {
            Log::critical(
                'Failed to update Facebook comment AI statistics or pipeline after permanent job failure.',
                [
                    'sync_run_id' => (string) $syncRun->id,
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

    /**
     * Record API-facing AI statistics once per sync run, outcome, and batch.
     *
     * These statistics count AI batches, not individual comments.
     *
     * @param array<int, string> $commentIds
     */
    private function recordAiStatisticsOnce(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
        string $outcome,
        array $commentIds,
    ): void {
        if (! in_array($outcome, ['completed', 'failed'], true)) {
            throw new AIException(
                "Unsupported comment AI outcome '{$outcome}'.",
            );
        }

        $canonicalIds = array_values(array_unique($commentIds));
        sort($canonicalIds, SORT_STRING);

        $operationKey = 'comment-ai-statistics:'
            . $outcome
            . ':'
            . hash(
                'sha256',
                $syncRunId . '|' . implode('|', $canonicalIds),
            );

        $syncRunService->incrementStatisticsOnce(
            runId: $syncRunId,
            operationKey: $operationKey,
            statistics: [
                ($outcome === 'completed' ? 'ai_completed' : 'ai_failed') => 1,
            ],
        );
    }

    /**
     * Increment a pipeline outcome once for this sync run and batch.
     *
     * The operation key is deterministic for the run, outcome, and batch.
     * Repeated accounting attempts are idempotent.
     *
     * @param array<int, string> $commentIds
     */
    private function recordPipelineOutcomeOnce(
        FacebookSyncRunService $syncRunService,
        string $syncRunId,
        string $outcome,
        array $commentIds,
    ): void {
        if (! in_array($outcome, ['completed', 'failed'], true)) {
            throw new AIException(
                "Unsupported comment AI pipeline outcome '{$outcome}'.",
            );
        }

        $counter = $outcome === 'completed'
            ? 'comment_ai_jobs_completed'
            : 'comment_ai_jobs_failed';

        $canonicalIds = array_values(array_unique($commentIds));
        sort($canonicalIds, SORT_STRING);

        $operationKey = 'comment-ai:'
            . $outcome
            . ':'
            . hash(
                'sha256',
                $syncRunId . '|' . implode('|', $canonicalIds),
            );

        $syncRunService->incrementPipelineOnce(
            runId: $syncRunId,
            operationKey: $operationKey,
            counters: [
                $counter => 1,
            ],
        );
    }

    /**
     * Normalize and deduplicate comment IDs.
     *
     * @param array<int, string> $commentIds
     * @return array<int, string>
     */
    private function normalizeCommentIds(array $commentIds): array
    {
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

    private function isRunRunning(FacebookSyncRun $syncRun): bool
    {
        return $this->statusValue($syncRun) === 'running';
    }

    private function statusValue(FacebookSyncRun $syncRun): ?string
    {
        $status = $syncRun->status;

        if (is_object($status) && isset($status->value)) {
            return (string) $status->value;
        }

        if (is_string($status)) {
            return $status;
        }

        return null;
    }

    /**
     * Record a bounded history of permanent AI failures.
     *
     * @param array<int, string> $commentIds
     */
    private function recordFailureMetadata(
        FacebookSyncRun $syncRun,
        ?Throwable $exception,
        array $commentIds,
    ): void {
        try {
            $metadata = $syncRun->metadata ?? [];

            if (! is_array($metadata)) {
                $metadata = [];
            }

            $failures = $metadata['comment_ai_failures'] ?? [];

            if (! is_array($failures)) {
                $failures = [];
            }

            $failures[] = [
                'comment_ids' => $commentIds,
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
                'exception_class' => $exception !== null
                    ? $exception::class
                    : null,
                'error' => $this->safeErrorMessage($exception),
                'failed_at' => now()->toISOString(),
            ];

            $metadata['comment_ai_failures'] = array_slice(
                $failures,
                -self::MAX_FAILURE_HISTORY,
            );

            $syncRun->forceFill([
                'metadata' => $metadata,
            ])->save();
        } catch (Throwable $metadataException) {
            Log::error(
                'Failed to persist Facebook comment AI failure metadata.',
                [
                    'sync_run_id' => (string) $syncRun->id,
                    'job_id' => $this->job?->getJobId(),
                    'exception_class' => $metadataException::class,
                    'exception_message' => $this->safeErrorMessage(
                        $metadataException,
                    ),
                ],
            );
        }
    }

    /**
     * Sanitize error messages before writing them to logs or metadata.
     */
    private function safeErrorMessage(?Throwable $exception): ?string
    {
        if ($exception === null) {
            return null;
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            return $exception::class;
        }

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/(Authorization:\s*Bearer\s+)[^\s,]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}
