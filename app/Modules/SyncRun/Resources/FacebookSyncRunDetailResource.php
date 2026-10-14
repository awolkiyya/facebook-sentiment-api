<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Resources;

use App\Models\FacebookSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FacebookSyncRun
 */
final class FacebookSyncRunDetailResource extends JsonResource
{
    /**
     * Transform the synchronization run into a detailed operational
     * representation.
     *
     * This resource is intended for:
     *
     * - Sync run detail page
     * - Active synchronization monitoring
     * - Progress indicators
     * - Statistics
     * - Error inspection
     */
    public function toArray(Request $request): array
    {
        $pipeline = $this->pipeline();

        $progress = $this->calculateProgress($pipeline);

        return [
            /*
             * -------------------------------------------------------------
             * Identity
             * -------------------------------------------------------------
             */
            'id' => (string) $this->getKey(),

            /*
             * Internal Laravel Facebook page UUID.
             */
            'facebook_page_id' => $this->facebook_page_id !== null
                ? (string) $this->facebook_page_id
                : null,

            /*
             * External Meta/Facebook Page ID.
             */
            'meta_page_id' => $this->whenLoaded(
                'page',
                fn () => $this->page?->facebook_page_id !== null
                    ? (string) $this->page->facebook_page_id
                    : null
            ),

            /*
             * -------------------------------------------------------------
             * Page
             * -------------------------------------------------------------
             */
            'page' => $this->whenLoaded(
                'page',
                fn () => $this->page === null
                    ? null
                    : [
                        'id' => (string) $this->page->getKey(),

                        'facebook_page_id' => $this->page->facebook_page_id !== null
                            ? (string) $this->page->facebook_page_id
                            : null,

                        'name' => $this->page->name,

                        'is_active' => (bool) $this->page->is_active,
                    ]
            ),

            /*
             * -------------------------------------------------------------
             * Synchronization
             * -------------------------------------------------------------
             */
            'type' => $this->type?->value,
            'trigger' => $this->trigger?->value,
            'status' => $this->status?->value,

            /*
             * -------------------------------------------------------------
             * Queue
             * -------------------------------------------------------------
             */
            'queue' => $this->queue,

            'job_id' => $this->job_id !== null
                ? (string) $this->job_id
                : null,

            'attempts' => (int) $this->attempts,

            /*
             * -------------------------------------------------------------
             * Triggered by
             * -------------------------------------------------------------
             */
            'triggered_by' => $this->whenLoaded(
                'triggeredBy',
                fn () => $this->triggeredBy === null
                    ? null
                    : [
                        'id' => (string) $this->triggeredBy->getKey(),
                        'name' => $this->triggeredBy->name,
                    ]
            ),

            /*
             * -------------------------------------------------------------
             * Synchronization statistics
             * -------------------------------------------------------------
             */
            'statistics' => [
                'posts' => [
                    'fetched' => (int) $this->posts_fetched,
                    'created' => (int) $this->posts_created,
                    'updated' => (int) $this->posts_updated,
                ],

                'comments' => [
                    'fetched' => (int) $this->comments_fetched,
                    'created' => (int) $this->comments_created,
                    'updated' => (int) $this->comments_updated,
                    'failed' => (int) $this->comments_failed,
                ],

                'ai' => [
                    'posts_queued' => (int) $this->posts_queued_for_ai,
                    'comments_queued' => (int) $this->comments_queued_for_ai,
                    'completed' => (int) $this->ai_completed,
                    'failed' => (int) $this->ai_failed,
                ],
            ],

            /*
             * -------------------------------------------------------------
             * Pipeline
             * -------------------------------------------------------------
             *
             * This is the most important section for the active SyncRun
             * frontend.
             *
             * The expected/completed/failed counters represent actual queue
             * jobs, not individual Facebook records.
             */
            'pipeline' => [
                'initialized' => (bool) ($pipeline['initialized'] ?? false),

                'completed' => (bool) ($pipeline['completed'] ?? false),

                'initialized_at' => $this->pipelineTimestamp(
                    $pipeline['initialized_at'] ?? null
                ),

                'completed_at' => $this->pipelineTimestamp(
                    $pipeline['completed_at'] ?? null
                ),

                'comment_jobs' => [
                    'expected' => $this->pipelineInteger(
                        $pipeline['comment_jobs_expected'] ?? 0
                    ),

                    'completed' => $this->pipelineInteger(
                        $pipeline['comment_jobs_completed'] ?? 0
                    ),

                    'failed' => $this->pipelineInteger(
                        $pipeline['comment_jobs_failed'] ?? 0
                    ),

                    'finished' => $this->pipelineFinished(
                        expected: $pipeline['comment_jobs_expected'] ?? 0,
                        completed: $pipeline['comment_jobs_completed'] ?? 0,
                        failed: $pipeline['comment_jobs_failed'] ?? 0,
                    ),

                    'progress_percent' => $this->pipelineProgressPercent(
                        expected: $pipeline['comment_jobs_expected'] ?? 0,
                        completed: $pipeline['comment_jobs_completed'] ?? 0,
                        failed: $pipeline['comment_jobs_failed'] ?? 0,
                    ),
                ],

                'post_ai_jobs' => [
                    'expected' => $this->pipelineInteger(
                        $pipeline['post_ai_jobs_expected'] ?? 0
                    ),

                    'completed' => $this->pipelineInteger(
                        $pipeline['post_ai_jobs_completed'] ?? 0
                    ),

                    'failed' => $this->pipelineInteger(
                        $pipeline['post_ai_jobs_failed'] ?? 0
                    ),

                    'finished' => $this->pipelineFinished(
                        expected: $pipeline['post_ai_jobs_expected'] ?? 0,
                        completed: $pipeline['post_ai_jobs_completed'] ?? 0,
                        failed: $pipeline['post_ai_jobs_failed'] ?? 0,
                    ),

                    'progress_percent' => $this->pipelineProgressPercent(
                        expected: $pipeline['post_ai_jobs_expected'] ?? 0,
                        completed: $pipeline['post_ai_jobs_completed'] ?? 0,
                        failed: $pipeline['post_ai_jobs_failed'] ?? 0,
                    ),
                ],

                'comment_ai_jobs' => [
                    'expected' => $this->pipelineInteger(
                        $pipeline['comment_ai_jobs_expected'] ?? 0
                    ),

                    'completed' => $this->pipelineInteger(
                        $pipeline['comment_ai_jobs_completed'] ?? 0
                    ),

                    'failed' => $this->pipelineInteger(
                        $pipeline['comment_ai_jobs_failed'] ?? 0
                    ),

                    'finished' => $this->pipelineFinished(
                        expected: $pipeline['comment_ai_jobs_expected'] ?? 0,
                        completed: $pipeline['comment_ai_jobs_completed'] ?? 0,
                        failed: $pipeline['comment_ai_jobs_failed'] ?? 0,
                    ),

                    'progress_percent' => $this->pipelineProgressPercent(
                        expected: $pipeline['comment_ai_jobs_expected'] ?? 0,
                        completed: $pipeline['comment_ai_jobs_completed'] ?? 0,
                        failed: $pipeline['comment_ai_jobs_failed'] ?? 0,
                    ),
                ],

                /*
                 * Overall pipeline progress.
                 */
                'overall' => $progress,
            ],

            /*
             * -------------------------------------------------------------
             * Lifecycle
             * -------------------------------------------------------------
             */
            'lifecycle' => [
                'queued_at' => $this->queued_at?->toIso8601String(),

                'started_at' => $this->started_at?->toIso8601String(),

                'completed_at' => $this->completed_at?->toIso8601String(),

                'duration_seconds' => $this->durationSeconds(),

                'is_active' => (bool) $this->isActive(),
            ],

            /*
             * -------------------------------------------------------------
             * Error
             * -------------------------------------------------------------
             */
            'error' => [
                'has_error' => $this->error_message !== null
                    && trim((string) $this->error_message) !== '',

                'message' => $this->safeErrorMessage(),
            ],

            /*
             * -------------------------------------------------------------
             * Metadata
             * -------------------------------------------------------------
             *
             * We expose metadata deliberately as a separate field.
             *
             * IMPORTANT:
             * This should only be returned to users who are authorized to
             * view operational SyncRun details.
             *
             * Sensitive Facebook credentials should never be stored in this
             * metadata in the first place.
             */
            'metadata' => $this->safeMetadata(),

            /*
             * -------------------------------------------------------------
             * Timestamps
             * -------------------------------------------------------------
             */
            'created_at' => $this->created_at?->toIso8601String(),

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get the pipeline metadata.
     *
     * FacebookSyncRun's metadata is expected to contain:
     *
     * metadata.pipeline
     */
    private function pipeline(): array
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return [];
        }

        $pipeline = $metadata['pipeline'] ?? [];

        return is_array($pipeline)
            ? $pipeline
            : [];
    }

    /**
     * Calculate overall pipeline progress.
     *
     * Progress is based on queue jobs:
     *
     * completed + failed
     * ----------------- × 100
     * expected
     *
     * Failed jobs are considered finished because the job lifecycle has
     * reached a terminal state.
     */
    private function calculateProgress(array $pipeline): array
    {
        $groups = [
            [
                'expected' => $pipeline['comment_jobs_expected'] ?? 0,
                'completed' => $pipeline['comment_jobs_completed'] ?? 0,
                'failed' => $pipeline['comment_jobs_failed'] ?? 0,
            ],
            [
                'expected' => $pipeline['post_ai_jobs_expected'] ?? 0,
                'completed' => $pipeline['post_ai_jobs_completed'] ?? 0,
                'failed' => $pipeline['post_ai_jobs_failed'] ?? 0,
            ],
            [
                'expected' => $pipeline['comment_ai_jobs_expected'] ?? 0,
                'completed' => $pipeline['comment_ai_jobs_completed'] ?? 0,
                'failed' => $pipeline['comment_ai_jobs_failed'] ?? 0,
            ],
        ];

        $expected = 0;
        $finished = 0;
        $completed = 0;
        $failed = 0;

        foreach ($groups as $group) {
            $groupExpected = $this->pipelineInteger(
                $group['expected']
            );

            $groupCompleted = $this->pipelineInteger(
                $group['completed']
            );

            $groupFailed = $this->pipelineInteger(
                $group['failed']
            );

            $expected += $groupExpected;
            $completed += min(
                $groupCompleted,
                $groupExpected
            );
            $failed += min(
                $groupFailed,
                max(0, $groupExpected - $groupCompleted)
            );

            $finished += min(
                $groupCompleted + $groupFailed,
                $groupExpected
            );
        }

        $percent = $expected > 0
            ? (int) round(($finished / $expected) * 100)
            : (
                ($pipeline['initialized'] ?? false)
                    ? 100
                    : 0
            );

        $percent = max(0, min(100, $percent));

        return [
            'expected' => $expected,
            'completed' => $completed,
            'failed' => $failed,
            'finished' => $finished,
            'remaining' => max(0, $expected - $finished),
            'progress_percent' => $percent,
            'is_finished' => $expected === 0
                ? (bool) ($pipeline['completed'] ?? false)
                : $finished >= $expected,
        ];
    }

    /**
     * Calculate progress for an individual pipeline group.
     */
    private function pipelineProgressPercent(
        mixed $expected,
        mixed $completed,
        mixed $failed,
    ): int {
        $expected = $this->pipelineInteger($expected);
        $completed = $this->pipelineInteger($completed);
        $failed = $this->pipelineInteger($failed);

        if ($expected <= 0) {
            return 100;
        }

        $finished = min(
            $completed + $failed,
            $expected
        );

        return max(
            0,
            min(
                100,
                (int) round(($finished / $expected) * 100)
            )
        );
    }

    /**
     * Determine whether an individual pipeline group has finished.
     */
    private function pipelineFinished(
        mixed $expected,
        mixed $completed,
        mixed $failed,
    ): bool {
        $expected = $this->pipelineInteger($expected);
        $completed = $this->pipelineInteger($completed);
        $failed = $this->pipelineInteger($failed);

        if ($expected <= 0) {
            return true;
        }

        return ($completed + $failed) >= $expected;
    }

    /**
     * Normalize a pipeline integer.
     */
    private function pipelineInteger(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    /**
     * Normalize a pipeline timestamp.
     */
    private function pipelineTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Return metadata without exposing sensitive credentials.
     *
     * This recursively removes keys commonly associated with Facebook
     * credentials/tokens.
     */
    private function safeMetadata(): array
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return [];
        }

        return $this->sanitizeMetadata($metadata);
    }

    /**
     * Recursively sanitize metadata.
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sensitiveKeys = [
            'access_token',
            'appsecret_proof',
            'app_secret',
            'client_secret',
            'secret',
            'token',
            'authorization',
            'password',
        ];

        $result = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (
                    $normalizedKey === $sensitiveKey
                    || str_contains($normalizedKey, $sensitiveKey)
                ) {
                    $result[$key] = '[REDACTED]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeMetadata($value);

                continue;
            }

            if (
                is_string($value)
                || is_int($value)
                || is_float($value)
                || is_bool($value)
                || $value === null
            ) {
                $result[$key] = $value;

                continue;
            }

            $result[$key] = (string) $value;
        }

        return $result;
    }

    /**
     * Return a safe synchronization error message.
     */
    private function safeErrorMessage(): ?string
    {
        if ($this->error_message === null) {
            return null;
        }

        $message = trim((string) $this->error_message);

        if ($message === '') {
            return null;
        }

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]+/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        $message = preg_replace(
            '/((?:access_token|appsecret_proof)[\'"]?\s*[:=]\s*)[^,\s}\'"]+/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}