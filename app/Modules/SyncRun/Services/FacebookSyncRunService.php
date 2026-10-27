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

    private const MAX_PIPELINE_OPERATION_KEYS = 5000;

    private const MAX_RETRY_HISTORY = 100;

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a synchronization run.
     *
     * The Facebook Page row lock serializes run creation and retry
     * operations for the same page, provided all creation paths use
     * this service and follow the same locking order.
     *
     * @param array<string, mixed> $metadata
     */
    public function create(
        string $facebookPageId,
        FacebookSyncType $type,
        FacebookSyncTrigger $trigger,
        ?string $triggeredByUserId = null,
        string $queue = 'facebook',
        array $metadata = [],
    ): FacebookSyncRun {
        $queue = trim($queue);

        if ($queue === '') {
            throw ValidationException::withMessages([
                'queue' => [
                    'The queue name cannot be empty.',
                ],
            ]);
        }

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

            if (! $page->is_active) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'This Facebook Page is inactive and cannot be synchronized.',
                    ],
                ]);
            }

            $activeRunExists = FacebookSyncRun::query()
                ->where('facebook_page_id', $facebookPageId)
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
                'facebook_page_id' => $facebookPageId,
                'triggered_by_user_id' => $triggeredByUserId,
                'trigger' => $trigger,
                'type' => $type,
                'status' => FacebookSyncStatus::QUEUED,
                'queue' => $queue,
                'attempts' => 0,
                'queued_at' => now(),
                'started_at' => null,
                'last_heartbeat_at' => null,
                'completed_at' => null,
                'finished_at' => null,
                'error_message' => null,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Running
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a queued run as running.
     *
     * A queue redelivery may encounter a run that is already running.
     * Such a redelivery refreshes the heartbeat but does not increment
     * the run attempt count again.
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

            $now = now();

            if ($run->status === FacebookSyncStatus::RUNNING) {
                $run->last_heartbeat_at = $now;

                if ($jobId !== null && $run->job_id === null) {
                    $run->job_id = $jobId;
                }

                $run->save();

                return $run->refresh();
            }

            if ($run->status !== FacebookSyncStatus::QUEUED) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot start a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            $run->status = FacebookSyncStatus::RUNNING;
            $run->started_at = $run->started_at ?? $now;
            $run->last_heartbeat_at = $now;
            $run->finished_at = null;
            $run->completed_at = null;
            $run->job_id = $jobId;
            $run->attempts = ((int) $run->attempts) + 1;
            $run->error_message = null;

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    */

    /**
     * Record activity for a running synchronization.
     *
     * Workers should call this periodically during long-running work.
     */
    public function heartbeat(string $runId): FacebookSyncRun
    {
        return DB::transaction(function () use (
            $runId,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            if ($run->status !== FacebookSyncStatus::RUNNING) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot record a heartbeat for a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            $run->last_heartbeat_at = now();
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
     * Cancel a queued or running synchronization.
     *
     * The queue worker must check the run status before starting
     * additional work. This method does not itself terminate a worker.
     */
    public function cancel(
        FacebookSyncRun $run,
        ?object $user = null,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $run,
            $user,
        ): FacebookSyncRun {
            /** @var FacebookSyncRun $lockedRun */
            $lockedRun = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if (! $lockedRun->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot cancel a sync run with status '{$lockedRun->status->value}'.",
                    ],
                ]);
            }

            $now = now();

            $metadata = is_array($lockedRun->metadata)
                ? $lockedRun->metadata
                : [];

            $metadata['cancellation'] = [
                'cancelled_at' => $now->toIso8601String(),
                'previous_status' => $lockedRun->status->value,
            ];

            if ($user !== null && isset($user->id)) {
                $metadata['cancellation']['cancelled_by_user_id'] =
                    (string) $user->id;
            }

            $lockedRun->metadata = $metadata;
            $lockedRun->status = FacebookSyncStatus::CANCELLED;

            // Cancellation is terminal, but not successful completion.
            $lockedRun->completed_at = null;
            $lockedRun->finished_at = $now;
            $lockedRun->last_heartbeat_at = $now;
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
     * Prepare a failed or cancelled run for retry.
     *
     * This method does not dispatch a queue job. The orchestration
     * layer must dispatch the correct root job after this transaction
     * succeeds.
     */
    public function retry(
        FacebookSyncRun $run,
        ?object $user = null,
    ): FacebookSyncRun {
        return DB::transaction(function () use (
            $run,
            $user,
        ): FacebookSyncRun {
            /*
             * Establish a consistent lock order:
             * Facebook Page first, then SyncRun.
             *
             * This reduces deadlock risk with create(), which also
             * locks the Facebook Page before checking active runs.
             */
            /** @var FacebookSyncRun $initialRun */
            $initialRun = FacebookSyncRun::query()
                ->findOrFail($run->getKey());

            /** @var FacebookPage|null $page */
            $page = FacebookPage::query()
                ->whereKey($initialRun->facebook_page_id)
                ->lockForUpdate()
                ->first();

            if ($page === null) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        FacebookPage::class,
                        [$initialRun->facebook_page_id],
                    );
            }

            /** @var FacebookSyncRun $lockedRun */
            $lockedRun = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if ($lockedRun->facebook_page_id !== $page->getKey()) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'The Facebook Page associated with this run has changed. Reload and try again.',
                    ],
                ]);
            }

            if (! $lockedRun->status->canRetry()) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot retry a sync run with status '{$lockedRun->status->value}'.",
                    ],
                ]);
            }

            if (! $page->is_active) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'This Facebook Page is inactive and cannot be synchronized.',
                    ],
                ]);
            }

            $activeRunExists = FacebookSyncRun::query()
                ->where('facebook_page_id', $lockedRun->facebook_page_id)
                ->whereIn('status', [
                    FacebookSyncStatus::QUEUED->value,
                    FacebookSyncStatus::RUNNING->value,
                ])
                ->where(
                    $lockedRun->getKeyName(),
                    '<>',
                    $lockedRun->getKey(),
                )
                ->exists();

            if ($activeRunExists) {
                throw ValidationException::withMessages([
                    'facebook_page_id' => [
                        'Another synchronization run is already queued or running for this Facebook Page.',
                    ],
                ]);
            }

            $now = now();

            $metadata = is_array($lockedRun->metadata)
                ? $lockedRun->metadata
                : [];

            $retryHistory = is_array($metadata['retry_history'] ?? null)
                ? $metadata['retry_history']
                : [];

            $retryRecord = [
                'retried_at' => $now->toIso8601String(),
                'previous_status' => $lockedRun->status->value,
                'previous_job_id' => $lockedRun->job_id,
                'previous_attempts' => (int) $lockedRun->attempts,
            ];

            if ($user !== null && isset($user->id)) {
                $retryRecord['retried_by_user_id'] = (string) $user->id;
            }

            $retryHistory[] = $retryRecord;

            if (count($retryHistory) > self::MAX_RETRY_HISTORY) {
                $retryHistory = array_slice(
                    $retryHistory,
                    -self::MAX_RETRY_HISTORY,
                );
            }

            $metadata['retry_history'] = $retryHistory;
            $metadata['retry_count'] = count($retryHistory);

            // Downstream pipeline state must be rebuilt for the new attempt.
            unset($metadata['pipeline'], $metadata['cancellation']);

            $lockedRun->metadata = $metadata;
            $lockedRun->status = FacebookSyncStatus::QUEUED;
            $lockedRun->queue = trim((string) $lockedRun->queue) !== ''
                ? $lockedRun->queue
                : 'facebook';

            $lockedRun->job_id = null;
            $lockedRun->started_at = null;
            $lockedRun->last_heartbeat_at = null;
            $lockedRun->completed_at = null;
            $lockedRun->finished_at = null;
            $lockedRun->error_message = null;
            $lockedRun->queued_at = $now;

            // Attempts are incremented when the worker actually starts.
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
     * Initialize asynchronous pipeline tracking exactly once.
     *
     * @param array<string, mixed> $counters
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

            $this->assertPipelineWritable($run);

            // Validate every supplied value before changing metadata.
            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter($key, $value);
            }

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = is_array($metadata['pipeline'] ?? null)
                ? $metadata['pipeline']
                : [];

            if (($pipeline['initialized'] ?? false) === true) {
                return $run->refresh();
            }

            foreach (self::PIPELINE_COUNTERS as $counter) {
                $pipeline[$counter] = 0;
            }

            foreach ($counters as $key => $value) {
                $pipeline[$key] = $value;
            }

            $now = now();

            $pipeline['operations'] = [];
            $pipeline['initialized'] = true;
            $pipeline['initialized_at'] = $now->toIso8601String();
            $pipeline['completed'] = false;
            unset($pipeline['completed_at']);

            $metadata['pipeline'] = $pipeline;

            $run->metadata = $metadata;
            $run->last_heartbeat_at = $now;
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
     * Increment pipeline counters.
     *
     * The row lock serializes read-modify-write updates to JSON metadata.
     *
     * @param array<string, mixed> $counters
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

            $this->assertPipelineWritable($run);

            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter($key, $value);
            }

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata($metadata);

            foreach ($counters as $key => $value) {
                $pipeline[$key] += $value;
            }

            $metadata['pipeline'] = $pipeline;

            $run->metadata = $metadata;
            $run->last_heartbeat_at = now();
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
     * Increment pipeline counters once per operation key.
     *
     * @param array<string, mixed> $counters
     * @return array{run: FacebookSyncRun, applied: bool}
     */
    public function incrementPipelineOnce(
        string $runId,
        string $operationKey,
        array $counters,
    ): array {
        $operationKey = trim($operationKey);

        if ($operationKey === '') {
            throw ValidationException::withMessages([
                'operation_key' => [
                    'The pipeline operation key cannot be empty.',
                ],
            ]);
        }

        if (strlen($operationKey) > 255) {
            throw ValidationException::withMessages([
                'operation_key' => [
                    'The pipeline operation key cannot exceed 255 characters.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $runId,
            $operationKey,
            $counters,
        ): array {
            /** @var FacebookSyncRun $run */
            $run = FacebookSyncRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $this->assertPipelineWritable($run);

            foreach ($counters as $key => $value) {
                $this->validatePipelineCounter($key, $value);
            }

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata($metadata);

            $operations = $pipeline['operations'];

            if (isset($operations[$operationKey])) {
                return [
                    'run' => $run->refresh(),
                    'applied' => false,
                ];
            }

            foreach ($counters as $key => $value) {
                $pipeline[$key] += $value;
            }

            $operations[$operationKey] = [
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($operations) > self::MAX_PIPELINE_OPERATION_KEYS) {
                $operations = array_slice(
                    $operations,
                    -self::MAX_PIPELINE_OPERATION_KEYS,
                    null,
                    true,
                );
            }

            $pipeline['operations'] = $operations;
            $metadata['pipeline'] = $pipeline;

            $run->metadata = $metadata;
            $run->last_heartbeat_at = now();
            $run->save();

            return [
                'run' => $run->refresh(),
                'applied' => true,
            ];
        }, attempts: 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Job Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Record downstream pipeline job outcomes.
     *
     * Example:
     * [
     *     'comment_jobs_completed' => 1,
     *     'comment_jobs_failed' => 0,
     * ]
     *
     * @param array<string, mixed> $counters
     */
    public function markPipelineJobsCompleted(
        string $runId,
        array $counters,
    ): FacebookSyncRun {
        return $this->incrementPipeline($runId, $counters);
    }

    /**
     * Record a downstream pipeline operation exactly once.
     *
     * @param array<string, mixed> $counters
     * @return array{run: FacebookSyncRun, applied: bool}
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

    public function isPipelineInitialized(
        FacebookSyncRun $run,
    ): bool {
        $metadata = is_array($run->metadata)
            ? $run->metadata
            : [];

        $pipeline = is_array($metadata['pipeline'] ?? null)
            ? $metadata['pipeline']
            : [];

        return ($pipeline['initialized'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPipeline(
        FacebookSyncRun $run,
    ): array {
        $metadata = is_array($run->metadata)
            ? $run->metadata
            : [];

        return $this->getPipelineMetadata($metadata);
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Return true when all expected downstream jobs have finished.
     *
     * A job is considered finished if it completed successfully or failed.
     */
    public function isPipelineComplete(
        FacebookSyncRun $run,
    ): bool {
        $pipeline = $this->getPipeline($run);

        if (($pipeline['initialized'] ?? false) !== true) {
            return false;
        }

        $groups = [
            [
                'expected' => 'comment_jobs_expected',
                'completed' => 'comment_jobs_completed',
                'failed' => 'comment_jobs_failed',
            ],
            [
                'expected' => 'post_ai_jobs_expected',
                'completed' => 'post_ai_jobs_completed',
                'failed' => 'post_ai_jobs_failed',
            ],
            [
                'expected' => 'comment_ai_jobs_expected',
                'completed' => 'comment_ai_jobs_completed',
                'failed' => 'comment_ai_jobs_failed',
            ],
        ];

        foreach ($groups as $group) {
            $expected = (int) $pipeline[$group['expected']];
            $completed = (int) $pipeline[$group['completed']];
            $failed = (int) $pipeline[$group['failed']];

            if (($completed + $failed) < $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Complete the run when all downstream pipeline jobs have finished.
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

            if ($run->isTerminal()) {
                return $run;
            }

            if ($run->status !== FacebookSyncStatus::RUNNING) {
                return $run;
            }

            if (! $this->isPipelineComplete($run)) {
                return $run;
            }

            $now = now();

            $metadata = is_array($run->metadata)
                ? $run->metadata
                : [];

            $pipeline = $this->getPipelineMetadata($metadata);
            $pipeline['completed'] = true;
            $pipeline['completed_at'] = $now->toIso8601String();

            $metadata['pipeline'] = $pipeline;

            $run->metadata = $metadata;
            $run->status = FacebookSyncStatus::COMPLETED;
            $run->completed_at = $now;
            $run->finished_at = $now;
            $run->last_heartbeat_at = $now;
            $run->error_message = null;

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
     * Increment synchronization statistics.
     *
     * All values are validated before any in-memory mutation.
     *
     * @param array<string, mixed> $statistics
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

            $this->assertStatisticsWritable($run);

            foreach ($statistics as $key => $value) {
                $this->validateStatistic($key, $value);
            }

            foreach ($statistics as $key => $value) {
                $run->{$key} = ((int) $run->{$key}) + $value;
            }

            if ($statistics !== []) {
                $run->last_heartbeat_at = now();
            }

            $run->save();

            return $run->refresh();
        }, attempts: 3);
    }

    /**
     * Set synchronization statistics to exact values.
     *
     * Only running runs may be modified.
     *
     * @param array<string, mixed> $statistics
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

            $this->assertStatisticsWritable($run);

            foreach ($statistics as $key => $value) {
                $this->validateStatistic($key, $value);
            }

            foreach ($statistics as $key => $value) {
                $run->{$key} = $value;
            }

            if ($statistics !== []) {
                $run->last_heartbeat_at = now();
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
     *
     * This method intentionally allows metadata updates on terminal runs,
     * for example to attach diagnostic information after a worker failure.
     * Callers must not use it to change lifecycle fields or overwrite
     * pipeline state without appropriate validation.
     *
     * @param array<string, mixed> $metadata
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
                ? array_replace_recursive($existing, $metadata)
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
     * Mark a running synchronization as successfully completed.
     *
     * Use only when there is no asynchronous downstream pipeline work
     * remaining. Otherwise, use completePipelineIfReady().
     *
     * @param array<string, mixed> $statistics
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

            if ($run->status !== FacebookSyncStatus::RUNNING) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot complete a sync run with status '{$run->status->value}'.",
                    ],
                ]);
            }

            foreach ($statistics as $key => $value) {
                $this->validateStatistic($key, $value);
            }

            foreach ($statistics as $key => $value) {
                $run->{$key} = $value;
            }

            $now = now();

            $run->status = FacebookSyncStatus::COMPLETED;
            $run->completed_at = $now;
            $run->finished_at = $now;
            $run->last_heartbeat_at = $now;
            $run->error_message = null;

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
     * Mark a queued or running run as failed.
     *
     * Terminal runs are immutable; late failures from already-finished
     * jobs do not overwrite their final status.
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

            if ($run->isTerminal()) {
                return $run;
            }

            $message = trim(
                $error instanceof Throwable
                    ? $error->getMessage()
                    : $error,
            );

            if ($message === '') {
                $message = 'Unknown synchronization error.';
            }

            $now = now();

            $run->status = FacebookSyncStatus::FAILED;

            // A failure is not a successful completion.
            $run->completed_at = null;
            $run->finished_at = $now;
            $run->last_heartbeat_at = $now;

            $run->error_message = Str::limit(
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

    public function find(string $runId): FacebookSyncRun
    {
        return FacebookSyncRun::query()->findOrFail($runId);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Helpers
    |--------------------------------------------------------------------------
    */

    private function assertPipelineWritable(
        FacebookSyncRun $run,
    ): void {
        if ($run->status !== FacebookSyncStatus::RUNNING) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot update pipeline for a sync run with status '{$run->status->value}'.",
                ],
            ]);
        }
    }

    private function assertStatisticsWritable(
        FacebookSyncRun $run,
    ): void {
        if ($run->status !== FacebookSyncStatus::RUNNING) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot update statistics for a sync run with status '{$run->status->value}'.",
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
    private function getPipelineMetadata(array $metadata): array
    {
        $pipeline = is_array($metadata['pipeline'] ?? null)
            ? $metadata['pipeline']
            : [];

        foreach (self::PIPELINE_COUNTERS as $counter) {
            $pipeline[$counter] = isset($pipeline[$counter])
                ? max(0, (int) $pipeline[$counter])
                : 0;
        }

        $pipeline['operations'] = is_array(
            $pipeline['operations'] ?? null,
        )
            ? $pipeline['operations']
            : [];

        $pipeline['initialized'] =
            ($pipeline['initialized'] ?? false) === true;

        $pipeline['completed'] =
            ($pipeline['completed'] ?? false) === true;

        return $pipeline;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    private function validateStatistic(
        string $key,
        mixed $value,
    ): void {
        if (! in_array($key, self::STATISTIC_FIELDS, true)) {
            throw ValidationException::withMessages([
                "statistics.{$key}" => [
                    'Unsupported synchronization statistic.',
                ],
            ]);
        }

        if (! is_int($value) || $value < 0) {
            throw ValidationException::withMessages([
                "statistics.{$key}" => [
                    'The statistic must be a non-negative integer.',
                ],
            ]);
        }
    }

    private function validatePipelineCounter(
        string $key,
        mixed $value,
    ): void {
        if (! in_array($key, self::PIPELINE_COUNTERS, true)) {
            throw ValidationException::withMessages([
                "pipeline.{$key}" => [
                    'Unsupported pipeline counter.',
                ],
            ]);
        }

        if (! is_int($value) || $value < 0) {
            throw ValidationException::withMessages([
                "pipeline.{$key}" => [
                    'The pipeline counter must be a non-negative integer.',
                ],
            ]);
        }
    }
}