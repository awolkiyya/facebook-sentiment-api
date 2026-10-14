<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Services;

use App\Enums\FacebookSyncStatus;
use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use App\Models\FacebookPage;
use App\Models\FacebookSyncRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FacebookSyncRunService
{
    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Statistics that can be updated by a synchronization process.
     */
    private const STATISTIC_FIELDS = [
        'posts_fetched',
        'posts_created',
        'posts_updated',

        'comments_fetched',
        'comments_created',
        'comments_updated',
        'comments_failed',

        'posts_queued_for_ai',
        'comments_queued_for_ai',

        'ai_completed',
        'ai_failed',
    ];

    /*
    |--------------------------------------------------------------------------
    | Pipeline Counters
    |--------------------------------------------------------------------------
    */

    /**
     * Pipeline counters stored inside metadata.
     *
     * These counters describe asynchronous downstream work.
     */
    private const PIPELINE_COUNTERS = [
        'comment_jobs_expected',
        'comment_jobs_completed',
        'comment_jobs_failed',

        'post_ai_jobs_expected',
        'post_ai_jobs_completed',
        'post_ai_jobs_failed',

        'comment_ai_jobs_expected',
        'comment_ai_jobs_completed',
        'comment_ai_jobs_failed',
    ];

    /**
     * Maximum number of operation keys retained in SyncRun metadata.
     */
    private const MAX_PIPELINE_OPERATION_KEYS = 5000;

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new synchronization run.
     *
     * Only one queued/running synchronization is allowed per Facebook Page.
     */
    public function create(
        string $facebookPageId,
        FacebookSyncType $type,
        FacebookSyncTrigger $trigger,
        ?string $triggeredByUserId = null,
        string $queue = 'facebook',
        array $metadata = [],
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $facebookPageId,
            $type,
            $trigger,
            $triggeredByUserId,
            $queue,
            $metadata,
        ): FacebookSyncRun {
            /** @var FacebookPage|null $page */
            $page = FacebookPage::query()
                ->whereKey($facebookPageId)
                ->lockForUpdate()
                ->first();

            if ($page === null) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        FacebookPage::class,
                        [$facebookPageId],
                    );
            }

            /*
             * A disconnected/deactivated page cannot start
             * a new synchronization.
             */
            if (! $page->is_active) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'This Facebook Page is inactive and cannot be synchronized.',
                    ],
                ]);
            }

            /*
             * Prevent concurrent synchronization for the same page.
             */
            $activeRunExists = FacebookSyncRun::query()
                ->where(
                    'facebook_page_id',
                    $facebookPageId,
                )
                ->whereIn('status', [
                    FacebookSyncStatus::QUEUED->value,
                    FacebookSyncStatus::RUNNING->value,
                ])
                ->exists();

            if ($activeRunExists) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'A synchronization run is already queued or running for this Facebook Page.',
                    ],
                ]);
            }

            return FacebookSyncRun::query()->create([
                'facebook_page_id' =>
                    $facebookPageId,

                'triggered_by_user_id' =>
                    $triggeredByUserId,

                'trigger' =>
                    $trigger,

                'type' =>
                    $type,

                'status' =>
                    FacebookSyncStatus::QUEUED,

                'queue' =>
                    $queue,

                'attempts' =>
                    0,

                'queued_at' =>
                    now(),

                'metadata' =>
                    $metadata !== []
                        ? $metadata
                        : null,
            ]);
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Running
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a queued synchronization run as running.
     */
    public function markRunning(
        string $runId,
        ?string $jobId = null,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $jobId,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            /*
             * A retry of the same root queue job may encounter
             * a RUNNING SyncRun.
             *
             * Do not move it backwards or fail merely because
             * the worker is retrying.
             */
            if (
                $run->status === FacebookSyncStatus::RUNNING
            ) {
                if (
                    $jobId !== null
                    && $run->job_id === null
                ) {
                    $run->job_id = $jobId;
                    $run->save();
                }

                return $run->refresh();
            }

            if (
                $run->status !== FacebookSyncStatus::QUEUED
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot start a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            $run->status =
                FacebookSyncStatus::RUNNING;

            $run->started_at =
                now();

            $run->job_id =
                $jobId;

            $run->attempts =
                ((int) $run->attempts) + 1;

            $run->error_message =
                null;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a queued or running synchronization run.
     *
     * The queue worker must also respect the CANCELLED status before
     * performing additional pipeline work.
     */
    public function cancel(
        FacebookSyncRun $run,
        ?object $user = null,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $run,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $lockedRun */
            $lockedRun = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            /*
             * A completed, failed, or already cancelled run
             * cannot be cancelled again.
             */
            if (
                ! $lockedRun->status->canCancel()
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot cancel a sync run with status '{$lockedRun->status->value}'.",
                    ],
                ]);
            }

            $metadata = is_array($lockedRun->metadata)
                ? $lockedRun->metadata
                : [];

            /*
             * Record cancellation information without destroying
             * the existing synchronization metadata.
             */
            $metadata['cancellation'] = [
                'cancelled_at' =>
                    now()->toIso8601String(),

                'previous_status' =>
                    $lockedRun->status->value,
            ];

            $lockedRun->metadata =
                $metadata;

            $lockedRun->status =
                FacebookSyncStatus::CANCELLED;

            $lockedRun->completed_at =
                now();

            $lockedRun->error_message =
                'Synchronization was cancelled by the user.';

            $lockedRun->save();

            return $lockedRun->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */

    /**
     * Prepare a failed/cancelled synchronization run for retry.
     *
     * IMPORTANT:
     * This method intentionally does not dispatch a queue job.
     *
     * The caller/job orchestration layer must dispatch the correct
     * root job according to the run type.
     *
     * This prevents this service from making assumptions about
     * SyncFacebookCommentsJob or the post synchronization job
     * constructor.
     */
    public function retry(
        FacebookSyncRun $run,
        ?object $user = null,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $run,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $lockedRun */
            $lockedRun = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            /*
             * Only FAILED and CANCELLED runs may be retried.
             */
            if (
                ! $lockedRun->status->canRetry()
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot retry a sync run with status '{$lockedRun->status->value}'.",
                    ],
                ]);
            }

            /*
             * Check again while holding the row lock to prevent
             * accidentally creating a second active run for the
             * same Facebook Page.
             */
            $activeRunExists = FacebookSyncRun::query()
                ->where(
                    'facebook_page_id',
                    $lockedRun->facebook_page_id,
                )
                ->whereIn('status', [
                    FacebookSyncStatus::QUEUED->value,
                    FacebookSyncStatus::RUNNING->value,
                ])
                ->whereKey('!=', $lockedRun->getKey())
                ->exists();

            if ($activeRunExists) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'Another synchronization run is already queued or running for this Facebook Page.',
                    ],
                ]);
            }

            /*
             * The Facebook Page must still be active.
             */
            /** @var FacebookPage|null $page */
            $page = FacebookPage::query()
                ->whereKey($lockedRun->facebook_page_id)
                ->lockForUpdate()
                ->first();

            if ($page === null) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        FacebookPage::class,
                        [$lockedRun->facebook_page_id],
                    );
            }

            if (! $page->is_active) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'This Facebook Page is inactive and cannot be synchronized.',
                    ],
                ]);
            }

            $metadata = is_array($lockedRun->metadata)
                ? $lockedRun->metadata
                : [];

            /*
             * Preserve the previous attempt information for
             * auditing/debugging.
             */
            $retryHistory = is_array(
                $metadata['retry_history'] ?? null,
            )
                ? $metadata['retry_history']
                : [];

            $retryHistory[] = [
                'retried_at' =>
                    now()->toIso8601String(),

                'previous_status' =>
                    $lockedRun->status->value,

                'previous_job_id' =>
                    $lockedRun->job_id,

                'previous_attempts' =>
                    (int) $lockedRun->attempts,
            ];

            /*
             * Avoid unlimited metadata growth.
             */
            if (count($retryHistory) > 100) {
                $retryHistory = array_slice(
                    $retryHistory,
                    -100,
                );
            }

            $metadata['retry_history'] =
                $retryHistory;

            $metadata['retry_count'] =
                count($retryHistory);

            /*
             * The existing pipeline must be rebuilt for the
             * new attempt.
             *
             * Keeping the old pipeline counters would cause
             * the new attempt to inherit completed/failed jobs
             * from the previous attempt.
             */
            unset($metadata['pipeline']);

            /*
             * Clear cancellation information from the new attempt.
             */
            unset($metadata['cancellation']);

            $lockedRun->metadata =
                $metadata;

            /*
             * Return the existing SyncRun to QUEUED.
             */
            $lockedRun->status =
                FacebookSyncStatus::QUEUED;

            $lockedRun->queue =
                $lockedRun->queue
                ?: 'facebook';

            /*
             * The next worker attempt will increment attempts
             * through markRunning().
             */
            $lockedRun->job_id =
                null;

            $lockedRun->started_at =
                null;

            $lockedRun->completed_at =
                null;

            $lockedRun->error_message =
                null;

            /*
             * queued_at represents this retry request.
             */
            $lockedRun->queued_at =
                now();

            $lockedRun->save();

            return $lockedRun->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Initialization
    |--------------------------------------------------------------------------
    */

    /**
     * Initialize asynchronous pipeline tracking.
     *
     * Initialization is idempotent.
     */
    public function initializePipeline(
        string $runId,
        array $counters = [],
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $counters,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status !== FacebookSyncStatus::RUNNING
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot initialize pipeline for a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = is_array(
                $metadata['pipeline'] ?? null,
            )
                ? $metadata['pipeline']
                : [];

            /*
             * If already initialized, preserve the counters.
             */
            if (
                ($pipeline['initialized'] ?? false) === true
            ) {
                return $run->refresh();
            }

            foreach (self::PIPELINE_COUNTERS as $counter) {
                $pipeline[$counter] = 0;
            }

            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter(
                    $key,
                    $value,
                );

                $pipeline[$key] = $value;
            }

            $pipeline['operations'] = [];

            $pipeline['initialized'] =
                true;

            $pipeline['initialized_at'] =
                now()->toIso8601String();

            $pipeline['completed'] =
                false;

            $metadata['pipeline'] =
                $pipeline;

            $run->metadata =
                $metadata;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Increment Pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * Increment asynchronous pipeline counters atomically.
     */
    public function incrementPipeline(
        string $runId,
        array $counters,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $counters,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $this->assertPipelineWritable(
                $run,
            );

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata(
                $metadata,
            );

            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter(
                    $key,
                    $value,
                );

                $pipeline[$key] += $value;
            }

            $metadata['pipeline'] =
                $pipeline;

            $run->metadata =
                $metadata;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotent Pipeline Increment
    |--------------------------------------------------------------------------
    */

    /**
     * Increment pipeline counters exactly once for an operation.
     *
     * @return array{
     *     run: FacebookSyncRun,
     *     applied: bool
     * }
     */
    public function incrementPipelineOnce(
        string $runId,
        string $operationKey,
        array $counters,
    ): array {
        return DB::transaction(function () use (
            $runId,
            $operationKey,
            $counters,
        ): array {
            $operationKey = trim(
                $operationKey,
            );

            if ($operationKey === '') {
                throw ValidationException::withMessages([
                    'operation_key' => [
                        'The pipeline operation key cannot be empty.',
                    ],
                ]);
            }

            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $this->assertPipelineWritable(
                $run,
            );

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata(
                $metadata,
            );

            $operations = is_array(
                $pipeline['operations'] ?? null,
            )
                ? $pipeline['operations']
                : [];

            /*
             * Critical idempotency guard.
             */
            if (
                isset($operations[$operationKey])
            ) {
                return [
                    'run' =>
                        $run->refresh(),

                    'applied' =>
                        false,
                ];
            }

            /*
             * Validate all counters before mutation.
             */
            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter(
                    $key,
                    $value,
                );
            }

            foreach ($counters as $key => $value) {
                $pipeline[$key] += $value;
            }

            $operations[$operationKey] = [
                'recorded_at' =>
                    now()->toIso8601String(),
            ];

            /*
             * Prevent unbounded metadata growth.
             */
            if (
                count($operations)
                > self::MAX_PIPELINE_OPERATION_KEYS
            ) {
                $operations = array_slice(
                    $operations,
                    -self::MAX_PIPELINE_OPERATION_KEYS,
                    null,
                    true,
                );
            }

            $pipeline['operations'] =
                $operations;

            $metadata['pipeline'] =
                $pipeline;

            $run->metadata =
                $metadata;

            $run->save();

            return [
                'run' =>
                    $run->refresh(),

                'applied' =>
                    true,
            ];
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Pipeline Jobs Completed
    |--------------------------------------------------------------------------
    */

    /**
     * Mark one or more downstream pipeline jobs as completed.
     */
    public function markPipelineJobsCompleted(
        string $runId,
        array $counters,
    ): FacebookSyncRun {
        return $this->incrementPipeline(
            $runId,
            $counters,
        );
    }

    /**
     * Mark a downstream pipeline operation as completed exactly once.
     *
     * @return array{
     *     run: FacebookSyncRun,
     *     applied: bool
     * }
     */
    public function markPipelineJobCompletedOnce(
        string $runId,
        string $operationKey,
        array $counters,
    ): array {
        return $this->incrementPipelineOnce(
            runId: $runId,
            operationKey: $operationKey,
            counters: $counters,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the asynchronous pipeline was initialized.
     */
    public function isPipelineInitialized(
        FacebookSyncRun $run,
    ): bool {
        $metadata = is_array($run->metadata)
            ? $run->metadata
            : [];

        $pipeline = is_array(
            $metadata['pipeline'] ?? null,
        )
            ? $metadata['pipeline']
            : [];

        return ($pipeline['initialized'] ?? false)
            === true;
    }

    /**
     * Return normalized pipeline metadata.
     *
     * @return array<string, mixed>
     */
    public function getPipeline(
        FacebookSyncRun $run,
    ): array {
        $metadata = is_array($run->metadata)
            ? $run->metadata
            : [];

        return $this->getPipelineMetadata(
            $metadata,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether all expected downstream work is finished.
     *
     * A pipeline is complete when:
     *
     * expected <= completed + failed
     *
     * for every asynchronous job category.
     */
    public function isPipelineComplete(
        FacebookSyncRun $run,
    ): bool {
        $pipeline = $this->getPipeline(
            $run,
        );

        if (
            ($pipeline['initialized'] ?? false)
            !== true
        ) {
            return false;
        }

        $groups = [
            [
                'expected' =>
                    'comment_jobs_expected',

                'completed' =>
                    'comment_jobs_completed',

                'failed' =>
                    'comment_jobs_failed',
            ],
            [
                'expected' =>
                    'post_ai_jobs_expected',

                'completed' =>
                    'post_ai_jobs_completed',

                'failed' =>
                    'post_ai_jobs_failed',
            ],
            [
                'expected' =>
                    'comment_ai_jobs_expected',

                'completed' =>
                    'comment_ai_jobs_completed',

                'failed' =>
                    'comment_ai_jobs_failed',
            ],
        ];

        foreach ($groups as $group) {
            $expected = max(
                0,
                (int) (
                    $pipeline[$group['expected']]
                    ?? 0
                ),
            );

            $completed = max(
                0,
                (int) (
                    $pipeline[$group['completed']]
                    ?? 0
                ),
            );

            $failed = max(
                0,
                (int) (
                    $pipeline[$group['failed']]
                    ?? 0
                ),
            );

            if (
                ($completed + $failed)
                < $expected
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Complete the synchronization run if all downstream
     * pipeline work has finished.
     */
    public function completePipelineIfReady(
        string $runId,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            /*
             * Terminal states are immutable.
             */
            if ($run->isTerminal()) {
                return $run;
            }

            if (
                $run->status
                !== FacebookSyncStatus::RUNNING
            ) {
                return $run;
            }

            if (
                ! $this->isPipelineComplete($run)
            ) {
                return $run;
            }

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata(
                $metadata,
            );

            $pipeline['completed'] =
                true;

            $pipeline['completed_at'] =
                now()->toIso8601String();

            $metadata['pipeline'] =
                $pipeline;

            $run->metadata =
                $metadata;

            $run->status =
                FacebookSyncStatus::COMPLETED;

            $run->completed_at =
                now();

            $run->error_message =
                null;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Increment one or more synchronization statistics.
     */
    public function incrementStatistics(
        string $runId,
        array $statistics,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $statistics,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status
                !== FacebookSyncStatus::RUNNING
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot update statistics for a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            foreach ($statistics as $key => $value) {
                $this->validateStatistic(
                    $key,
                    $value,
                );

                $run->{$key} =
                    ((int) $run->{$key}) + $value;
            }

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /**
     * Set synchronization statistics to exact values.
     */
    public function setStatistics(
        string $runId,
        array $statistics,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $statistics,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            foreach ($statistics as $key => $value) {
                $this->validateStatistic(
                    $key,
                    $value,
                );

                $run->{$key} =
                    $value;
            }

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Update run metadata.
     */
    public function updateMetadata(
        string $runId,
        array $metadata,
        bool $merge = true,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $metadata,
            $merge,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $existing = is_array($run->metadata)
                ? $run->metadata
                : [];

            $run->metadata = $merge
                ? array_replace_recursive(
                    $existing,
                    $metadata,
                )
                : $metadata;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Completed
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a running synchronization run as completed.
     *
     * This method should only be used when there is no asynchronous
     * downstream pipeline remaining.
     */
    public function markCompleted(
        string $runId,
        array $statistics = [],
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $statistics,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status
                !== FacebookSyncStatus::RUNNING
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot complete a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            foreach ($statistics as $key => $value) {
                $this->validateStatistic(
                    $key,
                    $value,
                );

                $run->{$key} =
                    $value;
            }

            $run->status =
                FacebookSyncStatus::COMPLETED;

            $run->completed_at =
                now();

            $run->error_message =
                null;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Failed
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a queued or running synchronization run as failed.
     */
    public function markFailed(
        string $runId,
        Throwable|string $error,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $runId,
            $error,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            /*
             * Terminal states are immutable.
             *
             * This includes CANCELLED.
             */
            if ($run->isTerminal()) {
                return $run;
            }

            $message = $error instanceof Throwable
                ? $error->getMessage()
                : $error;

            $message =
                trim($message);

            if ($message === '') {
                $message =
                    'Unknown synchronization error.';
            }

            $run->status =
                FacebookSyncStatus::FAILED;

            $run->completed_at =
                now();

            $run->error_message =
                Str::limit(
                    $message,
                    10000,
                    '...',
                );

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Find
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve a synchronization run.
     */
    public function find(
        string $runId,
    ): FacebookSyncRun {
        return FacebookSyncRun::query()
            ->findOrFail($runId);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Pipeline Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Assert that a pipeline can still be modified.
     */
    private function assertPipelineWritable(
        FacebookSyncRun $run,
    ): void {
        if (
            $run->status
            !== FacebookSyncStatus::RUNNING
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot update pipeline for a sync run with status '{$run->status->value}'.",
                ],
            ]);
        }
    }

    /**
     * Normalize pipeline metadata and counters.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function getPipelineMetadata(
        array $metadata,
    ): array {
        $pipeline = is_array(
            $metadata['pipeline'] ?? null,
        )
            ? $metadata['pipeline']
            : [];

        foreach (self::PIPELINE_COUNTERS as $counter) {
            $pipeline[$counter] =
                isset($pipeline[$counter])
                    ? max(
                        0,
                        (int) $pipeline[$counter],
                    )
                    : 0;
        }

        $pipeline['operations'] =
            is_array(
                $pipeline['operations'] ?? null,
            )
                ? $pipeline['operations']
                : [];

        $pipeline['initialized'] =
            ($pipeline['initialized'] ?? false)
            === true;

        $pipeline['completed'] =
            ($pipeline['completed'] ?? false)
            === true;

        return $pipeline;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate a synchronization statistic.
     */
    private function validateStatistic(
        string $key,
        mixed $value,
    ): void {
        if (
            ! in_array(
                $key,
                self::STATISTIC_FIELDS,
                true,
            )
        ) {
            throw ValidationException::withMessages([
                "statistics.{$key}" => [
                    'Unsupported synchronization statistic.',
                ],
            ]);
        }

        if (
            ! is_int($value)
            || $value < 0
        ) {
            throw ValidationException::withMessages([
                "statistics.{$key}" => [
                    'The statistic must be a non-negative integer.',
                ],
            ]);
        }
    }

    /**
     * Validate a pipeline counter.
     */
    private function validatePipelineCounter(
        string $key,
        mixed $value,
    ): void {
        if (
            ! in_array(
                $key,
                self::PIPELINE_COUNTERS,
                true,
            )
        ) {
            throw ValidationException::withMessages([
                "pipeline.{$key}" => [
                    'Unsupported pipeline counter.',
                ],
            ]);
        }

        if (
            ! is_int($value)
            || $value < 0
        ) {
            throw ValidationException::withMessages([
                "pipeline.{$key}" => [
                    'The pipeline counter must be a non-negative integer.',
                ],
            ]);
        }
    }
}