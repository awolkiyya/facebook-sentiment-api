<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPost;
use App\Models\FacebookPostAttachment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FacebookPostAttachmentSyncService
{
    /**
     * Synchronize attachments for a post.
     *
     * The caller should run this method within the post transaction.
     *
     * @param array<string, mixed> $payload
     */
    public function sync(
        FacebookPost $post,
        array $payload,
    ): void {
        if (! array_key_exists('attachments', $payload)) {
            return;
        }

        $attachments = data_get($payload, 'attachments.data', []);

        if (! is_array($attachments)) {
            throw new RuntimeException(
                'Facebook attachment data is invalid.'
            );
        }

        $normalized = [];

        foreach ($attachments as $position => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $this->flatten(
                attachment: $attachment,
                position: (int) $position,
                parentIndex: null,
                items: $normalized,
            );
        }

        DB::transaction(function () use ($post, $normalized): void {
            /*
             * Delete the existing tree in one operation. This avoids
             * stale attachments after a successful Meta refresh.
             *
             * The FK for parent_attachment_id must either cascade
             * deletes or the child rows must be deleted first.
             */
            $existingIds = FacebookPostAttachment::query()
                ->where('facebook_post_id', $post->id)
                ->pluck('id')
                ->all();

            if ($existingIds !== []) {
                FacebookPostAttachment::query()
                    ->whereIn('parent_attachment_id', $existingIds)
                    ->delete();

                FacebookPostAttachment::query()
                    ->where('facebook_post_id', $post->id)
                    ->delete();
            }

            $localIds = [];

            foreach ($normalized as $index => $item) {
                $parentId = $item['parent_index'] === null
                    ? null
                    : ($localIds[$item['parent_index']] ?? null);

                $attachment = FacebookPostAttachment::query()->create([
                    'facebook_post_id' => $post->id,
                    'parent_attachment_id' => $parentId,
                    'type' => $item['type'],
                    'media_type' => $item['media_type'],
                    'url' => $item['url'],
                    'media_url' => $item['media_url'],
                    'thumbnail_url' => $item['thumbnail_url'],
                    'target_id' => $item['target_id'],
                    'target_url' => $item['target_url'],
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'width' => $item['width'],
                    'height' => $item['height'],
                    'position' => $item['position'],
                    'raw_payload' => $item['raw_payload'],
                ]);

                $localIds[$index] = $attachment->id;
            }
        });
    }

    /**
     * Flatten nested attachments into parent-before-child order.
     *
     * @param array<string, mixed> $attachment
     * @param array<int, array<string, mixed>> $items
     */
    private function flatten(
        array $attachment,
        int $position,
        ?int $parentIndex,
        array &$items,
    ): void {
        $index = count($items);

        $items[] = [
            'parent_index' => $parentIndex,
            'type' => $this->nullableString(data_get($attachment, 'type')),
            'media_type' => $this->resolveMediaType($attachment),
            'url' => $this->nullableString(data_get($attachment, 'url')),
            'media_url' => $this->nullableString(
                data_get($attachment, 'media.source')
            ),
            'thumbnail_url' => $this->nullableString(
                data_get($attachment, 'media.image.src')
            ),
            'target_id' => $this->nullableString(
                data_get($attachment, 'target.id')
            ),
            'target_url' => $this->nullableString(
                data_get($attachment, 'target.url')
            ),
            'title' => $this->nullableString(data_get($attachment, 'title')),
            'description' => $this->nullableString(
                data_get($attachment, 'description')
            ),
            'width' => $this->nullableInteger(
                data_get($attachment, 'media.image.width')
            ),
            'height' => $this->nullableInteger(
                data_get($attachment, 'media.image.height')
            ),
            'position' => $position,
            'raw_payload' => $attachment,
        ];

        $children = data_get($attachment, 'subattachments.data', []);

        if (! is_array($children)) {
            return;
        }

        foreach ($children as $childPosition => $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->flatten(
                attachment: $child,
                position: (int) $childPosition,
                parentIndex: $index,
                items: $items,
            );
        }
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function resolveMediaType(array $attachment): string
    {
        $type = strtolower((string) data_get($attachment, 'type', ''));

        if ($type === 'album') {
            return 'album';
        }

        if (
            str_contains($type, 'video')
            || data_get($attachment, 'media.source') !== null
        ) {
            return 'video';
        }

        if (
            $type === 'photo'
            || data_get($attachment, 'media.image.src') !== null
        ) {
            return 'image';
        }

        if ($type === 'share') {
            return 'link';
        }

        if ($type === 'native_templates') {
            return 'unavailable';
        }

        return 'unknown';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
