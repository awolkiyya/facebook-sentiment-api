<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Services;

use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use App\Jobs\Facebook\SyncFacebookCommentsJob;
use App\Jobs\Facebook\SyncFacebookPostsJob;
use App\Models\FacebookPost;
use App\Models\FacebookSyncRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FacebookSyncDispatcherService
{
    public function __construct(
        private readonly FacebookSyncRunService $syncRunService,
    ) {
    }

    /**
     * Create a synchronization run and dispatch the appropriate job.
     *
     * Page-level synchronization:
     * - POSTS
     * - FULL
     * - INCREMENTAL
     *
     * Comment synchronization:
     * - COMMENTS
     *
     * Comment synchronization requires valid local Facebook post IDs.
     *
     * @param array<int, string> $postIds
     */
    public function dispatch(
        string $facebookPageId,
        FacebookSyncType $type,
        FacebookSyncTrigger $trigger,
        ?string $triggeredByUserId = null,
        array $metadata = [],
        array $postIds = [],
    ): FacebookSyncRun {
        $facebookPageId = trim($facebookPageId);

        if ($facebookPageId === '') {
            throw ValidationException::withMessages([
                'facebook_page_id' => [
                    'Facebook Page ID cannot be empty.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Comment Synchronization Requirements
        |--------------------------------------------------------------------------
        */

        if (
            $type === FacebookSyncType::COMMENTS
            && $postIds === []
        ) {
            throw ValidationException::withMessages([
                'post_ids' => [
                    'Post IDs are required when synchronization type is comments.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Comment Post IDs
        |--------------------------------------------------------------------------
        |
        | Every post must belong to the requested Facebook Page.
        |
        */

        if ($type === FacebookSyncType::COMMENTS) {
            $postIds = $this->validateAndNormalizePostIds(
                facebookPageId: $facebookPageId,
                postIds: $postIds,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create Synchronization Run
        |--------------------------------------------------------------------------
        */

        $run = $this->syncRunService->create(
            facebookPageId: $facebookPageId,
            type: $type,
            trigger: $trigger,
            triggeredByUserId: $triggeredByUserId,
            queue: $this->queueFor($type),
            metadata: array_merge(
                $metadata,
                [
                    'dispatcher' => self::class,
                ],
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Dispatch Job
        |--------------------------------------------------------------------------
        */

        try {
            $this->dispatchJob(
                run: $run,
                postIds: $postIds,
            );

            Log::info(
                'Facebook synchronization job dispatched successfully.',
                [
                    'sync_run_id' => $run->id,
                    'facebook_page_id' => $run->facebook_page_id,
                    'type' => $run->type->value,
                    'trigger' => $run->trigger->value,
                    'queue' => $run->queue,
                    'post_count' => count($postIds),
                ],
            );

            /*
             * IMPORTANT:
             *
             * FacebookSyncRun defines facebookPage() and
             * triggeredByUser(), not page() and triggeredBy().
             */
            return $run->fresh([
                'facebookPage',
                'triggeredByUser',
            ]);
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Dispatch Failure
            |--------------------------------------------------------------------------
            |
            | Preserve the run for auditability and record the failure.
            |
            */

            Log::error(
                'Failed to dispatch Facebook synchronization job.',
                [
                    'sync_run_id' => $run->id,
                    'facebook_page_id' => $run->facebook_page_id,
                    'type' => $run->type->value,
                    'trigger' => $run->trigger->value,
                    'queue' => $run->queue,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            try {
                $this->syncRunService->markFailed(
                    runId: $run->id,
                    error: $exception,
                );
            } catch (Throwable $markFailedException) {
                Log::critical(
                    'Failed to mark Facebook synchronization run as failed after dispatch failure.',
                    [
                        'sync_run_id' => $run->id,
                        'original_exception' => $exception::class,
                        'original_message' => $exception->getMessage(),
                        'mark_failed_exception' => $markFailedException::class,
                        'mark_failed_message' => $markFailedException->getMessage(),
                    ],
                );
            }

            throw $exception;
        }
    }

    /**
     * Dispatch the correct job for the synchronization type.
     *
     * @param array<int, string> $postIds
     */
    private function dispatchJob(
        FacebookSyncRun $run,
        array $postIds,
    ): void {
        match ($run->type) {
            FacebookSyncType::POSTS,
            FacebookSyncType::FULL,
            FacebookSyncType::INCREMENTAL => $this->dispatchPostsJob(
                $run,
            ),

            FacebookSyncType::COMMENTS => $this->dispatchCommentJobs(
                $run,
                $postIds,
            ),
        };
    }

    /**
     * Dispatch page-level post synchronization.
     */
    private function dispatchPostsJob(
        FacebookSyncRun $run,
    ): void {
        SyncFacebookPostsJob::dispatch(
            facebookPageUuid: (string) $run->facebook_page_id,
            syncRunId: (string) $run->id,
        )->onQueue('facebook');
    }

    /**
     * Dispatch comment synchronization jobs for specific posts.
     *
     * Each post receives its own queue job.
     *
     * @param array<int, string> $postIds
     */
    private function dispatchCommentJobs(
        FacebookSyncRun $run,
        array $postIds,
    ): void {
        foreach ($postIds as $postId) {
            SyncFacebookCommentsJob::dispatch(
                postId: $postId,
                syncRunId: (string) $run->id,
            )->onQueue('facebook-comments');
        }
    }

    /**
     * Determine the queue for the synchronization type.
     */
    private function queueFor(
        FacebookSyncType $type,
    ): string {
        return match ($type) {
            FacebookSyncType::COMMENTS => 'facebook-comments',

            FacebookSyncType::POSTS,
            FacebookSyncType::FULL,
            FacebookSyncType::INCREMENTAL => 'facebook',
        };
    }

    /**
     * Validate and normalize post IDs for comment synchronization.
     *
     * These IDs refer to facebook_posts.id, not the external
     * Facebook post identifier stored in facebook_post_id.
     *
     * @param array<int, string> $postIds
     * @return array<int, string>
     */
    private function validateAndNormalizePostIds(
        string $facebookPageId,
        array $postIds,
    ): array {
        $normalized = [];

        foreach ($postIds as $postId) {
            if (! is_string($postId)) {
                continue;
            }

            $postId = trim($postId);

            if ($postId === '') {
                continue;
            }

            $normalized[$postId] = $postId;
        }

        $normalized = array_values($normalized);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'post_ids' => [
                    'At least one valid Facebook post ID is required.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Posts Belong to the Requested Page
        |--------------------------------------------------------------------------
        */

        $validPostIds = FacebookPost::query()
            ->where('facebook_page_id', $facebookPageId)
            ->whereIn('id', $normalized)
            ->pluck('id')
            ->map(
                static fn ($id): string => (string) $id,
            )
            ->all();

        $validPostIdMap = array_fill_keys(
            $validPostIds,
            true,
        );

        $invalidPostIds = [];

        foreach ($normalized as $postId) {
            if (! isset($validPostIdMap[$postId])) {
                $invalidPostIds[] = $postId;
            }
        }

        if ($invalidPostIds !== []) {
            throw ValidationException::withMessages([
                'post_ids' => [
                    'One or more posts do not belong to the selected Facebook Page.',
                ],
                'invalid_post_ids' => $invalidPostIds,
            ]);
        }

        return $normalized;
    }
}