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
    public function toArray(Request $request): array
    {
        $pipeline = $this->pipeline();

        return [
            'id' => (string) $this->getKey(),

            // Internal Laravel UUID and external Meta Page ID.
            'facebook_page_id' => $this->facebook_page_id !== null
                ? (string) $this->facebook_page_id
                : null,

            'meta_page_id' => $this->whenLoaded(
                'facebookPage',
                fn () => $this->facebookPage?->facebook_page_id !== null
                    ? (string) $this->facebookPage->facebook_page_id
                    : null,
            ),

            'page' => $this->whenLoaded(
                'facebookPage',
                fn () => $this->facebookPage === null
                    ? null
                    : [
                        'id' => (string) $this->facebookPage->getKey(),
                        'facebook_page_id' => $this->facebookPage->facebook_page_id !== null
                            ? (string) $this->facebookPage->facebook_page_id
                            : null,
                        'name' => $this->facebookPage->name,
                        'is_active' => (bool) $this->facebookPage->is_active,
                    ],
            ),

            // Synchronization identity.
            'type' => $this->type?->value,
            'trigger' => $this->trigger?->value,
            'status' => $this->status?->value,

            // Queue.
            'queue' => $this->queue,
            'job_id' => $this->job_id !== null
                ? (string) $this->job_id
                : null,
            'attempts' => (int) $this->attempts,

            // User who initiated the run; null for automatic runs.
            'triggered_by' => $this->whenLoaded(
                'triggeredByUser',
                fn () => $this->triggeredByUser === null
                    ? null
                    : [
                        'id' => (string) $this->triggeredByUser->getKey(),
                        'name' => $this->triggeredByUser->name,
                    ],
            ),

            // Synchronization statistics.
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

            // Pipeline state and queue-job progress.
            'pipeline' => [
                'initialized' => (bool) ($pipeline['initialized'] ?? false),
                'completed' => (bool) ($pipeline['completed'] ?? false),

                'initialized_at' => $this->pipelineTimestamp(
                    $pipeline['initialized_at'] ?? null,
                ),
                'completed_at' => $this->pipelineTimestamp(
                    $pipeline['completed_at'] ?? null,
                ),

                'comment_jobs' => $this->pipelineGroup(
                    $pipeline,
                    'comment_jobs',
                ),

                'post_ai_jobs' => $this->pipelineGroup(
                    $pipeline,
                    'post_ai_jobs',
                ),

                'comment_ai_jobs' => $this->pipelineGroup(
                    $pipeline,
                    'comment_ai_jobs',
                ),

                'overall' => $this->calculateProgress($pipeline),
            ],

            // Lifecycle.
            'lifecycle' => [
                'queued_at' => $this->queued_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'finished_at' => $this->finished_at?->toIso8601String(),
                'duration_seconds' => $this->durationSeconds(),
                'is_active' => $this->isActive(),
                'is_finished' => $this->isFinished(),
            ],

            // Error details.
            'error' => [
                'has_error' => $this->error_message !== null
                    && trim((string) $this->error_message) !== '',
                'message' => $this->safeErrorMessage(),
            ],

            // Operational metadata is sanitized before exposure.
            'metadata' => $this->safeMetadata(),

            // Record timestamps.
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Read pipeline metadata from metadata.pipeline.
     *
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return [];
        }

        $pipeline = $metadata['pipeline'] ?? [];

        return is_array($pipeline) ? $pipeline : [];
    }

    /**
     * Build the progress representation for a pipeline job group.
     *
     * @param array<string, mixed> $pipeline
     * @return array<string, int|bool>
     */
    private function pipelineGroup(
        array $pipeline,
        string $prefix,
    ): array {
        $expected = $pipeline["{$prefix}_expected"] ?? 0;
        $completed = $pipeline["{$prefix}_completed"] ?? 0;
        $failed = $pipeline["{$prefix}_failed"] ?? 0;

        $expected = $this->pipelineInteger($expected);
        $completed = $this->pipelineInteger($completed);
        $failed = $this->pipelineInteger($failed);

        $finished = min($completed + $failed, $expected);

        return [
            'expected' => $expected,
            'completed' => min($completed, $expected),
            'failed' => min($failed, max(0, $expected - $completed)),
            'finished' => $expected <= 0 || $finished >= $expected,
            'progress_percent' => $expected <= 0
                ? 100
                : (int) round(($finished / $expected) * 100),
        ];
    }

    /**
     * Calculate overall pipeline progress using actual queue-job counts.
     *
     * Failed jobs count as finished, but are not counted as completed.
     *
     * @param array<string, mixed> $pipeline
     * @return array<string, int|bool>
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

        $expectedTotal = 0;
        $completedTotal = 0;
        $failedTotal = 0;
        $finishedTotal = 0;

        foreach ($groups as $group) {
            $expected = $this->pipelineInteger($group['expected']);
            $completed = min(
                $this->pipelineInteger($group['completed']),
                $expected,
            );
            $failed = min(
                $this->pipelineInteger($group['failed']),
                max(0, $expected - $completed),
            );

            $expectedTotal += $expected;
            $completedTotal += $completed;
            $failedTotal += $failed;
            $finishedTotal += min($completed + $failed, $expected);
        }

        $initialized = (bool) ($pipeline['initialized'] ?? false);
        $pipelineCompleted = (bool) ($pipeline['completed'] ?? false);

        $percent = $expectedTotal > 0
            ? (int) round(($finishedTotal / $expectedTotal) * 100)
            : ($pipelineCompleted ? 100 : 0);

        return [
            'expected' => $expectedTotal,
            'completed' => $completedTotal,
            'failed' => $failedTotal,
            'finished' => $finishedTotal,
            'remaining' => max(0, $expectedTotal - $finishedTotal),
            'progress_percent' => max(0, min(100, $percent)),
            'is_finished' => $expectedTotal > 0
                ? $finishedTotal >= $expectedTotal
                : $pipelineCompleted,
        ];
    }

    /**
     * Normalize a non-negative integer value.
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
     * Return a pipeline timestamp when it is a non-empty string.
     */
    private function pipelineTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Return metadata after recursively removing sensitive values.
     *
     * @return array<string, mixed>
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
     * Recursively redact keys that may contain credentials or secrets.
     *
     * @param array<string|int, mixed> $metadata
     * @return array<string|int, mixed>
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

            // Do not cast arbitrary objects to strings.
            $result[$key] = '[UNSUPPORTED_VALUE]';
        }

        return $result;
    }

    /**
     * Return a sanitized synchronization error message.
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

        // Redact credentials embedded in query parameters.
        $message = preg_replace(
            '~([?&](?:access_token|appsecret_proof)=)[^&\s]+~i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        // Redact credentials embedded in key/value error messages.
        $message = preg_replace(
            '~((?:access_token|appsecret_proof)["\']?\s*[:=]\s*)[^,\s}"\']+~i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}