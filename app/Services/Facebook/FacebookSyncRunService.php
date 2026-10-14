<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookSyncRun;
use Throwable;

class FacebookSyncRunService
{
    /**
     * Start a new Facebook synchronization run.
     *
     * @param array<string, mixed> $metadata
     */
    public function start(
        string $facebookPageId,
        string $type,
        string $trigger = 'system',
        ?string $triggeredByUserId = null,
        array $metadata = [],
    ): FacebookSyncRun {
        return FacebookSyncRun::query()->create([
            'facebook_page_id' => $facebookPageId,

            'triggered_by_user_id' => $triggeredByUserId,

            'type' => $type,

            'trigger' => $trigger,

            'status' => 'running',

            'started_at' => now(),

            'metadata' => $metadata !== []
                ? $metadata
                : null,
        ]);
    }

    /**
     * Mark a synchronization run as completed.
     *
     * @param array<string, mixed> $statistics
     */
    public function complete(
        FacebookSyncRun $run,
        array $statistics = [],
    ): FacebookSyncRun {
        $run->update([
            'status' => 'completed',

            'completed_at' => now(),

            'posts_fetched' => max(
                0,
                (int) ($statistics['posts_fetched'] ?? 0),
            ),

            'comments_fetched' => max(
                0,
                (int) ($statistics['comments_fetched'] ?? 0),
            ),

            'comments_created' => max(
                0,
                (int) ($statistics['comments_created'] ?? 0),
            ),

            'comments_updated' => max(
                0,
                (int) ($statistics['comments_updated'] ?? 0),
            ),

            'comments_failed' => max(
                0,
                (int) ($statistics['comments_failed'] ?? 0),
            ),

            'metadata' => array_key_exists(
                'metadata',
                $statistics,
            )
                ? $statistics['metadata']
                : $run->metadata,
        ]);

        return $run->refresh();
    }

    /**
     * Mark a synchronization run as failed.
     */
    public function fail(
        FacebookSyncRun $run,
        Throwable|string $error,
    ): FacebookSyncRun {
        $run->update([
            'status' => 'failed',

            'completed_at' => now(),

            'error_message' => $error instanceof Throwable
                ? $error->getMessage()
                : $error,
        ]);

        return $run->refresh();
    }

    /**
     * Increment the number of fetched posts.
     */
    public function incrementPosts(
        FacebookSyncRun $run,
        int $amount = 1,
    ): void {
        $amount = max(0, $amount);

        if ($amount === 0) {
            return;
        }

        $run->increment(
            'posts_fetched',
            $amount,
        );
    }

    /**
     * Increment comment synchronization statistics.
     */
    public function incrementComments(
        FacebookSyncRun $run,
        int $fetched = 0,
        int $created = 0,
        int $updated = 0,
        int $failed = 0,
    ): void {
        $fetched = max(0, $fetched);
        $created = max(0, $created);
        $updated = max(0, $updated);
        $failed = max(0, $failed);

        if ($fetched > 0) {
            $run->increment(
                'comments_fetched',
                $fetched,
            );
        }

        if ($created > 0) {
            $run->increment(
                'comments_created',
                $created,
            );
        }

        if ($updated > 0) {
            $run->increment(
                'comments_updated',
                $updated,
            );
        }

        if ($failed > 0) {
            $run->increment(
                'comments_failed',
                $failed,
            );
        }
    }

    /**
     * Add or replace metadata on a synchronization run.
     *
     * @param array<string, mixed> $metadata
     */
    public function updateMetadata(
        FacebookSyncRun $run,
        array $metadata,
    ): FacebookSyncRun {
        $existingMetadata = is_array($run->metadata)
            ? $run->metadata
            : [];

        $run->update([
            'metadata' => array_merge(
                $existingMetadata,
                $metadata,
            ),
        ]);

        return $run->refresh();
    }
}