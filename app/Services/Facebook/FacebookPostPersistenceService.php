<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class FacebookPostPersistenceService
{
    public function __construct(
        private readonly FacebookPostAttachmentSyncService $attachments,
    ) {}

    /**
     * Create/update one post.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     post: FacebookPost,
     *     created: bool,
     *     changed: bool,
     *     requires_comment_sync: bool,
     *     comment_sync_reason: ?string
     * }
     */
    public function persist(
        FacebookPage $page,
        array $payload,
    ): array {
        $externalId = $payload['id'] ?? null;

        if (! is_string($externalId) || trim($externalId) === '') {
            throw new RuntimeException(
                'Cannot persist a Facebook post without its Meta ID.'
            );
        }

        $externalId = trim($externalId);

        return DB::transaction(function () use (
            $page,
            $payload,
            $externalId,
        ): array {
            $post = FacebookPost::query()
                ->where('facebook_post_id', $externalId)
                ->lockForUpdate()
                ->first();

            $created = $post === null;

            if ($post === null) {
                $post = new FacebookPost();
                $post->facebook_page_id = $page->id;
                $post->facebook_post_id = $externalId;
            } elseif ((string) $post->facebook_page_id !== (string) $page->id) {
                throw new RuntimeException(
                    'Facebook post belongs to a different local page.'
                );
            }

            $oldMessage = $post->message;
            $oldCommentCount = $post->comment_count;
            $oldLikeCount = $post->like_count;
            $oldShareCount = $post->share_count;

            /*
             * Only overwrite a field when Meta returned it. This prevents
             * partial payloads from erasing previously stored values.
             */
            if (array_key_exists('message', $payload)) {
                $post->message = $this->nullableString($payload['message']);
            }

            if (array_key_exists('permalink_url', $payload)) {
                $post->permalink_url = $this->nullableString(
                    $payload['permalink_url']
                );
            }

            if (array_key_exists('created_time', $payload)) {
                $post->published_at = $this->parseDate(
                    $payload['created_time']
                );
            }

            $likeCount = $this->extractCount($payload, 'likes');
            $commentCount = $this->extractCount($payload, 'comments');
            $shareCount = $this->extractShareCount($payload);

            if ($likeCount !== null) {
                $post->like_count = $likeCount;
            } elseif ($created) {
                $post->like_count = 0;
            }

            if ($commentCount !== null) {
                $post->comment_count = $commentCount;
            } elseif ($created) {
                $post->comment_count = 0;
            }

            if ($shareCount !== null) {
                $post->share_count = $shareCount;
            } elseif ($created) {
                $post->share_count = 0;
            }

            $post->raw_payload = $payload;
            $post->save();

            /*
             * Keep the attachment replacement inside this transaction.
             * If it fails, both the post and attachment changes roll back.
             */
            $this->attachments->sync($post, $payload);

            $messageChanged = $created
                || (
                    array_key_exists('message', $payload)
                    && $oldMessage !== $post->message
                );

            $commentsChanged = $created
                || (
                    $commentCount !== null
                    && (int) $oldCommentCount !== $commentCount
                );

            $engagementChanged = $created
                || (
                    $likeCount !== null
                    && (int) $oldLikeCount !== $likeCount
                )
                || (
                    $shareCount !== null
                    && (int) $oldShareCount !== $shareCount
                );

            $reason = match (true) {
                $created => 'new_post',
                $messageChanged => 'message_changed',
                $commentsChanged => 'comment_count_changed',
                default => null,
            };

            return [
                'post' => $post,
                'created' => $created,
                'changed' => $messageChanged
                    || $commentsChanged
                    || $engagementChanged,
                'requires_comment_sync' => $reason !== null,
                'comment_sync_reason' => $reason,
            ];
        }, 3);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractCount(
        array $payload,
        string $field,
    ): ?int {
        if (! array_key_exists($field, $payload)) {
            return null;
        }

        $count = data_get($payload, "{$field}.summary.total_count");

        return is_numeric($count) ? max(0, (int) $count) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractShareCount(array $payload): ?int
    {
        if (! array_key_exists('shares', $payload)) {
            return null;
        }

        $count = data_get($payload, 'shares.count');

        return is_numeric($count) ? max(0, (int) $count) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
