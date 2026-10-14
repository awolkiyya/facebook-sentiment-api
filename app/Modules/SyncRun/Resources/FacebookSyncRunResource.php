<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Resources;

use App\Models\FacebookSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FacebookSyncRun
 */
final class FacebookSyncRunResource extends JsonResource
{
    /**
     * Transform the synchronization run into an array.
     *
     * Optimized for:
     *
     * - Sync run list
     * - Dashboard widgets
     * - Recent synchronization cards
     * - Tables
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * -------------------------------------------------------------
             * Identity
             * -------------------------------------------------------------
             */
            'id' => (string) $this->getKey(),

            /*
             * Internal Laravel UUID.
             *
             * facebook_sync_runs.facebook_page_id
             * -> facebook_pages.id
             */
            'facebook_page_id' => $this->facebook_page_id !== null
                ? (string) $this->facebook_page_id
                : null,

            /*
             * External Meta/Facebook Page ID.
             */
            'meta_page_id' => $this->whenLoaded(
                'facebookPage',
                fn () => $this->facebookPage?->facebook_page_id !== null
                    ? (string) $this->facebookPage->facebook_page_id
                    : null,
            ),

            /*
             * -------------------------------------------------------------
             * Page
             * -------------------------------------------------------------
             *
             * API contract intentionally uses "page".
             *
             * Eloquent relationship is "facebookPage".
             */
            'page' => $this->whenLoaded(
                'facebookPage',
                fn () => $this->facebookPage === null
                    ? null
                    : [
                        'id' => (string) $this->facebookPage->getKey(),

                        'facebook_page_id' =>
                            $this->facebookPage->facebook_page_id !== null
                                ? (string) $this->facebookPage->facebook_page_id
                                : null,

                        'name' =>
                            $this->facebookPage->name,

                        'is_active' =>
                            (bool) $this->facebookPage->is_active,
                    ],
            ),

            /*
             * -------------------------------------------------------------
             * Synchronization identity
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
             *
             * API contract uses "triggered_by".
             *
             * Eloquent relationship is "triggeredByUser".
             */
            'triggered_by' => $this->whenLoaded(
                'triggeredByUser',
                fn () => $this->triggeredByUser === null
                    ? null
                    : [
                        'id' =>
                            (string) $this->triggeredByUser->getKey(),

                        'name' =>
                            $this->triggeredByUser->name,
                    ],
            ),

            /*
             * -------------------------------------------------------------
             * Statistics
             * -------------------------------------------------------------
             */
            'statistics' => [
                'posts_fetched' =>
                    (int) $this->posts_fetched,

                'posts_created' =>
                    (int) $this->posts_created,

                'posts_updated' =>
                    (int) $this->posts_updated,

                'comments_fetched' =>
                    (int) $this->comments_fetched,

                'comments_created' =>
                    (int) $this->comments_created,

                'comments_updated' =>
                    (int) $this->comments_updated,

                'comments_failed' =>
                    (int) $this->comments_failed,

                'posts_queued_for_ai' =>
                    (int) $this->posts_queued_for_ai,

                'comments_queued_for_ai' =>
                    (int) $this->comments_queued_for_ai,

                'ai_completed' =>
                    (int) $this->ai_completed,

                'ai_failed' =>
                    (int) $this->ai_failed,
            ],

            /*
             * -------------------------------------------------------------
             * Lifecycle
             * -------------------------------------------------------------
             */
            'queued_at' =>
                $this->queued_at?->toIso8601String(),

            'started_at' =>
                $this->started_at?->toIso8601String(),

            'completed_at' =>
                $this->completed_at?->toIso8601String(),

            'is_active' =>
                $this->isActive(),

            'duration_seconds' =>
                $this->durationSeconds(),

            /*
             * -------------------------------------------------------------
             * Error
             * -------------------------------------------------------------
             */
            'has_error' =>
                $this->error_message !== null
                && trim((string) $this->error_message) !== '',

            'error_message' => $this->when(
                $this->status?->value === 'failed',
                fn () => $this->safeErrorMessage(),
            ),

            /*
             * -------------------------------------------------------------
             * Timestamps
             * -------------------------------------------------------------
             */
            'created_at' =>
                $this->created_at?->toIso8601String(),

            'updated_at' =>
                $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Return a sanitized error message.
     */
    private function safeErrorMessage(): ?string
    {
        if ($this->error_message === null) {
            return null;
        }

        $message = trim(
            (string) $this->error_message,
        );

        if ($message === '') {
            return null;
        }

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/((?:access_token|appsecret_proof)[\'"]?\s*[:=]\s*)[^,\s}\'"]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr(
            $message,
            0,
            1000,
        );
    }
}