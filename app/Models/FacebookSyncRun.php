<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FacebookSyncStatus;
use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookSyncRun extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'facebook_sync_runs';

    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
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
        'completed_at',
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
            /*
            |--------------------------------------------------------------------------
            | Enums
            |--------------------------------------------------------------------------
            */

            'trigger' => FacebookSyncTrigger::class,

            'type' => FacebookSyncType::class,

            'status' => FacebookSyncStatus::class,

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            'queued_at' => 'datetime',

            'started_at' => 'datetime',

            'completed_at' => 'datetime',

            /*
            |--------------------------------------------------------------------------
            | Integers
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | JSON
            |--------------------------------------------------------------------------
            */

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
     *
     * facebook_sync_runs.facebook_page_id
     *      -> facebook_pages.id
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
     * User who triggered the synchronization.
     *
     * NULL for scheduler/system-triggered synchronization.
     *
     * facebook_sync_runs.triggered_by_user_id
     *      -> users.id
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
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the synchronization is queued.
     */
    public function isQueued(): bool
    {
        return $this->status === FacebookSyncStatus::QUEUED;
    }

    /**
     * Determine whether the synchronization is currently running.
     */
    public function isRunning(): bool
    {
        return $this->status === FacebookSyncStatus::RUNNING;
    }

    /**
     * Determine whether the synchronization completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === FacebookSyncStatus::COMPLETED;
    }

    /**
     * Determine whether the synchronization failed.
     */
    public function isFailed(): bool
    {
        return $this->status === FacebookSyncStatus::FAILED;
    }

    /**
     * Determine whether the synchronization is in an active state.
     *
     * Active states:
     * - queued
     * - running
     */
    public function isActive(): bool
    {
        return in_array(
            $this->status,
            [
                FacebookSyncStatus::QUEUED,
                FacebookSyncStatus::RUNNING,
            ],
            true,
        );
    }

    /**
     * Determine whether the synchronization reached a terminal state.
     *
     * Terminal states:
     * - completed
     * - failed
     */
    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            [
                FacebookSyncStatus::COMPLETED,
                FacebookSyncStatus::FAILED,
            ],
            true,
        );
    }

    /**
     * Determine whether the synchronization is finished.
     *
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
     * Get synchronization duration in seconds.
     *
     * Returns:
     * - null when the synchronization has not started yet.
     * - elapsed seconds while running.
     * - total duration after completion/failure.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return max(
            0,
            (int) $this->started_at->diffInSeconds($end),
        );
    }
   

    /*
    |--------------------------------------------------------------------------
    | State Transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Mark the synchronization run as running.
     *
     * This method increments the attempt counter.
     */
    public function markAsRunning(): void
    {
        $this->forceFill([
            'status' => FacebookSyncStatus::RUNNING,

            'started_at' => now(),

            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /**
     * Mark the synchronization run as completed.
     */
    public function markAsCompleted(): void
    {
        $this->forceFill([
            'status' => FacebookSyncStatus::COMPLETED,

            'completed_at' => now(),

            'error_message' => null,
        ])->save();
    }

    /**
     * Mark the synchronization run as failed.
     */
    public function markAsFailed(
        ?string $errorMessage = null,
    ): void {
        $this->forceFill([
            'status' => FacebookSyncStatus::FAILED,

            'completed_at' => now(),

            'error_message' => $errorMessage,
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Counter Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Increment post synchronization statistics.
     */
    public function incrementPostStatistics(
        int $fetched = 0,
        int $created = 0,
        int $updated = 0,
    ): void {
        if ($fetched > 0) {
            $this->increment(
                'posts_fetched',
                $fetched,
            );
        }

        if ($created > 0) {
            $this->increment(
                'posts_created',
                $created,
            );
        }

        if ($updated > 0) {
            $this->increment(
                'posts_updated',
                $updated,
            );
        }
    }

    /**
     * Increment comment synchronization statistics.
     */
    public function incrementCommentStatistics(
        int $fetched = 0,
        int $created = 0,
        int $updated = 0,
        int $failed = 0,
    ): void {
        if ($fetched > 0) {
            $this->increment(
                'comments_fetched',
                $fetched,
            );
        }

        if ($created > 0) {
            $this->increment(
                'comments_created',
                $created,
            );
        }

        if ($updated > 0) {
            $this->increment(
                'comments_updated',
                $updated,
            );
        }

        if ($failed > 0) {
            $this->increment(
                'comments_failed',
                $failed,
            );
        }
    }

    /**
     * Increment AI statistics.
     */
    public function incrementAiStatistics(
        int $postsQueued = 0,
        int $commentsQueued = 0,
        int $completed = 0,
        int $failed = 0,
    ): void {
        if ($postsQueued > 0) {
            $this->increment(
                'posts_queued_for_ai',
                $postsQueued,
            );
        }

        if ($commentsQueued > 0) {
            $this->increment(
                'comments_queued_for_ai',
                $commentsQueued,
            );
        }

        if ($completed > 0) {
            $this->increment(
                'ai_completed',
                $completed,
            );
        }

        if ($failed > 0) {
            $this->increment(
                'ai_failed',
                $failed,
            );
        }
    }
}