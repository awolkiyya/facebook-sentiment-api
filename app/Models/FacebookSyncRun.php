<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FacebookSyncStatus;
use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class FacebookSyncRun extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table Configuration
    |--------------------------------------------------------------------------
    */

    protected $table = 'facebook_sync_runs';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    |
    | Internal lifecycle methods use forceFill().
    | These attributes are not intended to be mass-assigned directly
    | from untrusted HTTP request data.
    |
    */

    protected $fillable = [
        'facebook_page_id',
        'triggered_by_user_id',
        'trigger',
        'type',
        'status',
        'queue',
        'job_id',
        'attempts',
        'queued_at',
        'started_at',
        'last_heartbeat_at',
        'completed_at',
        'finished_at',
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
        'error_message',
        'metadata',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'trigger' => FacebookSyncTrigger::class,

            'type' => FacebookSyncType::class,

            'status' => FacebookSyncStatus::class,

            'queued_at' => 'immutable_datetime',

            'started_at' => 'immutable_datetime',

            'last_heartbeat_at' => 'immutable_datetime',

            'completed_at' => 'immutable_datetime',

            'finished_at' => 'immutable_datetime',

            'attempts' => 'integer',

            'posts_fetched' => 'integer',

            'posts_created' => 'integer',

            'posts_updated' => 'integer',

            'comments_fetched' => 'integer',

            'comments_created' => 'integer',

            'comments_updated' => 'integer',

            'comments_failed' => 'integer',

            'posts_queued_for_ai' => 'integer',

            'comments_queued_for_ai' => 'integer',

            'ai_completed' => 'integer',

            'ai_failed' => 'integer',

            'metadata' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Facebook Page associated with this synchronization run.
     */
    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(
            FacebookPage::class,
            'facebook_page_id',
            'id',
        );
    }

    /**
     * User who initiated the synchronization.
     *
     * Returns null when the run was triggered automatically
     * or when the triggering user has been deleted.
     */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'triggered_by_user_id',
            'id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict a query to queued synchronization runs.
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where(
            'status',
            FacebookSyncStatus::QUEUED->value,
        );
    }

    /**
     * Restrict a query to running synchronization runs.
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where(
            'status',
            FacebookSyncStatus::RUNNING->value,
        );
    }

    /**
     * Restrict a query to active synchronization runs.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            [
                FacebookSyncStatus::QUEUED->value,
                FacebookSyncStatus::RUNNING->value,
            ],
        );
    }

    /**
     * Restrict a query to terminal synchronization runs.
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            [
                FacebookSyncStatus::COMPLETED->value,
                FacebookSyncStatus::FAILED->value,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isQueued(): bool
    {
        return $this->status === FacebookSyncStatus::QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === FacebookSyncStatus::RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === FacebookSyncStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === FacebookSyncStatus::FAILED;
    }

    public function isActive(): bool
    {
        return $this->isQueued() || $this->isRunning();
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }

    /**
     * Alias for isTerminal().
     */
    public function isFinished(): bool
    {
        return $this->isTerminal();
    }

    /*
    |--------------------------------------------------------------------------
    | Duration
    |--------------------------------------------------------------------------
    */

    /**
     * Return the synchronization duration in seconds.
     *
     * Returns null if execution has not started.
     *
     * Uses finished_at for terminal runs and the current time
     * for runs that are still executing.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? now();

        return max(
            0,
            (int) $this->started_at->diffInSeconds($end),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Management
    |--------------------------------------------------------------------------
    |
    | These methods update the synchronization lifecycle.
    | They do not dispatch jobs, manage distributed locks, or guarantee
    | that only one synchronization can run for a Facebook Page.
    |
    */

    /**
     * Mark the synchronization run as running.
     *
     * Preserves the first start timestamp across retries.
     * Increments attempts atomically in the database.
     */
    public function markAsRunning(): void
    {
        $this->assertNotFinished();

        $now = now();

        $this->newQuery()
            ->whereKey($this->getKey())
            ->increment('attempts');

        $this->forceFill([
            'status' => FacebookSyncStatus::RUNNING,

            'started_at' => $this->started_at ?? $now,

            'last_heartbeat_at' => $now,

            'completed_at' => null,

            'finished_at' => null,

            'error_message' => null,
        ])->save();

        $this->refresh();
    }

    /**
     * Update the last execution heartbeat.
     *
     * Call periodically during long-running synchronization work.
     */
    public function recordHeartbeat(): void
    {
        if (!$this->isRunning()) {
            throw new \LogicException(
                'A heartbeat can only be recorded for a running synchronization.',
            );
        }

        $now = now();

        $this->forceFill([
            'last_heartbeat_at' => $now,
        ])->save();
    }

    /**
     * Mark the synchronization as successfully completed.
     *
     * This represents synchronization completion, not necessarily
     * completion of all downstream AI processing.
     */
    public function markAsCompleted(): void
    {
        if (!$this->isRunning()) {
            throw new \LogicException(
                'Only a running synchronization can be completed.',
            );
        }

        $now = now();

        $this->forceFill([
            'status' => FacebookSyncStatus::COMPLETED,

            'completed_at' => $now,

            'finished_at' => $now,

            'last_heartbeat_at' => $now,

            'error_message' => null,
        ])->save();
    }

    /**
     * Mark the synchronization as permanently failed.
     *
     * Call this when the job reaches its terminal failure condition,
     * not for an intermediate attempt that Laravel will retry.
     */
    public function markAsFailed(
        ?string $errorMessage = null,
    ): void {
        $this->assertNotFinished();

        $now = now();

        $this->forceFill([
            'status' => FacebookSyncStatus::FAILED,

            'completed_at' => null,

            'finished_at' => $now,

            'last_heartbeat_at' => $now,

            'error_message' => $errorMessage,
        ])->save();
    }

    /**
     * Prevent ordinary lifecycle methods from reopening a terminal run.
     */
    private function assertNotFinished(): void
    {
        if ($this->isTerminal()) {
            throw new \LogicException(
                'A terminal synchronization run cannot be restarted.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Post Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Atomically increment post synchronization counters.
     *
     * Counters represent the totals reported by the synchronization
     * service. Callers must avoid reporting the same results twice.
     */
    public function incrementPostStatistics(
        int $fetched = 0,
        int $created = 0,
        int $updated = 0,
    ): void {
        $this->incrementCounters([
            'posts_fetched' => $fetched,
            'posts_created' => $created,
            'posts_updated' => $updated,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Comment Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Atomically increment comment synchronization counters.
     */
    public function incrementCommentStatistics(
        int $fetched = 0,
        int $created = 0,
        int $updated = 0,
        int $failed = 0,
    ): void {
        $this->incrementCounters([
            'comments_fetched' => $fetched,
            'comments_created' => $created,
            'comments_updated' => $updated,
            'comments_failed' => $failed,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | AI Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Atomically increment AI dispatch and outcome counters.
     *
     * completed and failed should represent final task outcomes,
     * not intermediate queue attempts.
     *
     * This method does not guarantee idempotency across repeated
     * delivery of the same AI job. The caller must enforce that.
     */
    public function incrementAiStatistics(
        int $postsQueued = 0,
        int $commentsQueued = 0,
        int $completed = 0,
        int $failed = 0,
    ): void {
        $this->incrementCounters([
            'posts_queued_for_ai' => $postsQueued,
            'comments_queued_for_ai' => $commentsQueued,
            'ai_completed' => $completed,
            'ai_failed' => $failed,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Atomic Counter Updates
    |--------------------------------------------------------------------------
    */

    /**
     * Increment a controlled set of counters atomically.
     *
     * Column names are supplied only by the internal methods above.
     * Negative increments are rejected.
     *
     * Refreshes the current model so its attributes reflect database
     * updates made by this method.
     *
     * Atomic increments prevent lost updates, but do not prevent
     * duplicate counting if a job reports the same result twice.
     *
     * @param array<string, int> $counters
     */
    private function incrementCounters(array $counters): void
    {
        $updates = [];

        foreach ($counters as $column => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException(
                    "Counter increments cannot be negative: {$column}.",
                );
            }

            if ($amount === 0) {
                continue;
            }

            $updates[$column] = $this->rawCounterExpression(
                $column,
                $amount,
            );
        }

        if ($updates === []) {
            return;
        }

        $updated = $this->newQuery()
            ->whereKey($this->getKey())
            ->update($updates);

        if ($updated === 0) {
            throw new \RuntimeException(
                'The synchronization run no longer exists.',
            );
        }

        $this->refresh();
    }

    /**
     * Build an arithmetic expression for an internally controlled counter.
     *
     * @return \Illuminate\Database\Query\Expression
     */
    private function rawCounterExpression(
        string $column,
        int $amount,
    ): \Illuminate\Database\Query\Expression {
        $allowedColumns = [
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

        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException(
                "Unsupported synchronization counter: {$column}.",
            );
        }

        return \Illuminate\Support\Facades\DB::raw(
            "{$column} + {$amount}",
        );
    }
}