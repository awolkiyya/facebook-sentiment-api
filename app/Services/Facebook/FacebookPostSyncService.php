<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class FacebookPostSyncService
{
    private const MAX_PAGES = 100;

    private const MAX_POSTS_PER_SYNC = 10_000;

    public function __construct(
        private readonly FacebookGraphApiService $graphApi,
        private readonly FacebookPostPersistenceService $persistence,
    ) {}

    /**
     * Synchronize posts and return local post UUIDs requiring comment sync.
     *
     * @return array<int, string>
     */
    public function sync(FacebookPage $page): array
    {
        $this->validatePage($page);

        $accessToken = $this->resolveAccessToken($page);

        $nextUrl = $this->graphApi->buildPostsUrl(
            (string) $page->facebook_page_id
        );

        $visitedUrls = [];
        $commentSyncIds = [];
        $pageCount = 0;
        $postCount = 0;
        $bounded = false;

        Log::info('Facebook post synchronization started.', [
            'page_internal_id' => $page->id,
            'facebook_page_id' => $page->facebook_page_id,
            'max_pages' => self::MAX_PAGES,
            'max_posts' => self::MAX_POSTS_PER_SYNC,
        ]);

        try {
            while ($nextUrl !== null) {
                if ($pageCount >= self::MAX_PAGES) {
                    $bounded = true;
                    break;
                }

                if ($postCount >= self::MAX_POSTS_PER_SYNC) {
                    $bounded = true;
                    break;
                }

                $requestUrl = $this->graphApi->preparePaginationUrl(
                    $nextUrl
                );

                $fingerprint = $this->graphApi->paginationFingerprint(
                    $requestUrl
                );

                if (isset($visitedUrls[$fingerprint])) {
                    throw new RuntimeException(
                        'Meta returned a repeated pagination URL.'
                    );
                }

                $visitedUrls[$fingerprint] = true;
                $pageCount++;

                $result = $this->graphApi->fetchPosts(
                    page: $page,
                    url: $requestUrl,
                    accessToken: $accessToken,
                    firstPage: $pageCount === 1,
                );

                $posts = $result['data'];

                Log::info('Facebook post page received.', [
                    'page_internal_id' => $page->id,
                    'pagination_page' => $pageCount,
                    'post_count' => count($posts),
                ]);

                foreach ($posts as $payload) {
                    if ($postCount >= self::MAX_POSTS_PER_SYNC) {
                        $bounded = true;
                        break 2;
                    }

                    $persisted = $this->persistence->persist(
                        page: $page,
                        payload: $payload,
                    );

                    $postCount++;

                    if ($persisted['requires_comment_sync']) {
                        $localId = (string) $persisted['post']->id;

                        if ($localId !== '') {
                            $commentSyncIds[$localId] = $localId;
                        }
                    }
                }

                $nextUrl = $result['next'];
            }

            /*
             * Only update the timestamp after successful processing.
             * A bounded run has not necessarily fetched the full history.
             */
            if (! $bounded) {
                $page->last_synced_at = now();
                $page->save();
            }

            Log::info('Facebook post synchronization completed.', [
                'page_internal_id' => $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'pages_processed' => $pageCount,
                'posts_processed' => $postCount,
                'comment_sync_count' => count($commentSyncIds),
                'bounded_completion' => $bounded,
                'last_synced_at_updated' => ! $bounded,
            ]);

            return array_values($commentSyncIds);
        } catch (Throwable $exception) {
            Log::error('Facebook post synchronization failed.', [
                'page_internal_id' => $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'pages_processed' => $pageCount,
                'posts_processed' => $postCount,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function validatePage(FacebookPage $page): void
    {
        if (! $page->exists) {
            throw new RuntimeException(
                'Facebook page must exist before synchronization.'
            );
        }

        if (
            ! is_string($page->facebook_page_id)
            || trim($page->facebook_page_id) === ''
        ) {
            throw new RuntimeException(
                'Facebook page does not have a valid Meta Page ID.'
            );
        }
    }

    private function resolveAccessToken(FacebookPage $page): string
    {
        $token = $page->access_token_encrypted;

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException(
                'Facebook page does not have a valid access token.'
            );
        }

        if ($page->hasExpiredToken()) {
            throw new RuntimeException(
                'Facebook page access token has expired. Reconnect the page.'
            );
        }

        return trim($token);
    }
}
