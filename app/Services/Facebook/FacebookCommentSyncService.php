<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use App\Services\Http\HttpClientService;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookCommentSyncService
{
    private const GRAPH_API_VERSION = 'v26.0';

    private const PAGE_LIMIT = 100;

    private const MAX_PAGES = 100;

    private const GRAPH_API_HOST = 'graph.facebook.com';

    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {
    }

    /**
     * Synchronize comments for a Facebook post.
     *
     * @return array{
     *     comment_ids: array<int, string>,
     *     statistics: array{
     *         comments_fetched: int,
     *         comments_created: int,
     *         comments_updated: int
     *     },
     *     pages_processed: int
     * }
     */
    public function sync(FacebookPost $post): array
    {
        $this->validatePost($post);

        $accessToken = $this->resolveAccessToken($post);

        $commentIdsForAnalysis = [];
        $commentsFetched = 0;
        $commentsCreated = 0;
        $commentsUpdated = 0;

        $nextUrl = $this->buildCommentsUrl(
            (string) $post->facebook_post_id,
        );

        $pageCount = 0;
        $visitedUrls = [];

        while ($nextUrl !== null) {
            $pageCount++;

            if ($pageCount > self::MAX_PAGES) {
                throw new RuntimeException(
                    sprintf(
                        'Maximum Facebook comment pagination limit of %d pages was reached for post %s.',
                        self::MAX_PAGES,
                        $post->facebook_post_id,
                    ),
                );
            }

            $this->validatePaginationUrl($nextUrl);

            $urlFingerprint = hash('sha256', $nextUrl);

            if (isset($visitedUrls[$urlFingerprint])) {
                throw new RuntimeException(
                    'Meta returned a repeated Facebook comment pagination URL.',
                );
            }

            $visitedUrls[$urlFingerprint] = true;

            $response = $this->fetchComments(
                url: $nextUrl,
                accessToken: $accessToken,
                firstPage: $pageCount === 1,
            );

            $data = $response['data'] ?? null;

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Meta comments response does not contain a valid data array.',
                );
            }

            foreach ($data as $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $facebookCommentId = $payload['id'] ?? null;

                if (
                    ! is_string($facebookCommentId)
                    || trim($facebookCommentId) === ''
                ) {
                    continue;
                }

                $commentsFetched++;

                $result = $this->upsertComment(
                    post: $post,
                    payload: $payload,
                );

                if ($result['created']) {
                    $commentsCreated++;
                } elseif ($result['updated']) {
                    $commentsUpdated++;
                }

                if ($result['requires_ai']) {
                    $localCommentId = (string) $result['comment']->getKey();

                    $commentIdsForAnalysis[$localCommentId] = $localCommentId;
                }
            }

            $nextUrl = $this->resolveNextPageUrl($response);
        }

        $statistics = [
            'comments_fetched' => $commentsFetched,
            'comments_created' => $commentsCreated,
            'comments_updated' => $commentsUpdated,
        ];

        Log::info(
            'Facebook comment synchronization completed.',
            [
                'post_internal_id' => $post->getKey(),
                'facebook_post_id' => $post->facebook_post_id,
                'pages_processed' => $pageCount,
                ...$statistics,
                'comments_queued_for_ai' => count($commentIdsForAnalysis),
            ],
        );

        return [
            'comment_ids' => array_values($commentIdsForAnalysis),
            'statistics' => $statistics,
            'pages_processed' => $pageCount,
        ];
    }

    private function validatePost(FacebookPost $post): void
    {
        if (! $post->exists) {
            throw new RuntimeException(
                'Facebook post must exist before comments can be synchronized.',
            );
        }

        if (
            ! is_string($post->getKey())
            || trim($post->getKey()) === ''
        ) {
            throw new RuntimeException(
                'Facebook post must have an internal ID.',
            );
        }

        if (
            ! is_string($post->facebook_post_id)
            || trim($post->facebook_post_id) === ''
        ) {
            throw new RuntimeException(
                'Facebook post does not have a valid Meta Facebook post ID.',
            );
        }
    }

    private function resolveAccessToken(FacebookPost $post): string
    {
        $page = $post->facebookPage;

        if ($page === null) {
            throw new RuntimeException(
                'Facebook post is not associated with a Facebook page.',
            );
        }

        /*
         * This assumes access_token_encrypted uses Laravel's encrypted
         * cast, so reading the attribute returns the decrypted token.
         */
        $accessToken = $page->access_token_encrypted;

        if (
            ! is_string($accessToken)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException(
                'Facebook page does not have a valid access token.',
            );
        }

        return trim($accessToken);
    }

    private function buildCommentsUrl(string $facebookPostId): string
    {
        return sprintf(
            'https://%s/%s/%s/comments',
            self::GRAPH_API_HOST,
            self::GRAPH_API_VERSION,
            rawurlencode($facebookPostId),
        );
    }

    /**
     * @return array<string, string|int>
     */
    private function buildCommentsQuery(
        string $accessToken,
        bool $firstPage,
    ): array {
        $query = [
            'access_token' => $accessToken,
        ];

        if ($firstPage) {
            $query['fields'] = implode(',', [
                'id',
                'message',
                'from',
                'parent',
                'permalink_url',
                'created_time',
                'like_count',
            ]);

            $query['limit'] = self::PAGE_LIMIT;
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchComments(
        string $url,
        string $accessToken,
        bool $firstPage,
    ): array {
        $this->validatePaginationUrl($url);

        try {
            $query = $this->buildCommentsQuery(
                accessToken: $accessToken,
                firstPage: $firstPage,
            );

            Log::debug(
                'Fetching Facebook comments page.',
                [
                    'first_page' => $firstPage,
                    'query_keys' => array_keys($query),
                    'access_token_present' => true,
                    'url' => $this->sanitizeUrlForLog($url),
                ],
            );

            $response = $this->httpClient->get(
                url: $url,
                query: $query,
                timeout: 30,
            );

            $json = $response->json();

            if (! is_array($json)) {
                throw new RuntimeException(
                    'Meta Graph API returned an invalid comments response.',
                );
            }

            if (isset($json['error'])) {
                $message = data_get($json, 'error.message');

                throw new RuntimeException(
                    is_string($message)
                        ? 'Meta Graph API error: '
                            . mb_substr($message, 0, 500)
                        : 'Meta Graph API returned an unknown error.',
                );
            }

            return $json;
        } catch (RequestException $exception) {
            /*
             * Do not include the raw response body or request URL in
             * the exception. They may contain sensitive information.
             */
            $message = $exception->response?->json('error.message');

            throw new RuntimeException(
                is_string($message)
                    ? 'Meta Graph API comment request failed: '
                        . mb_substr($message, 0, 500)
                    : 'Meta Graph API comment request failed.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Failed to retrieve Facebook comments from Meta.',
                previous: $exception,
            );
        }
    }

    private function validatePaginationUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new RuntimeException(
                'Meta returned an invalid Facebook pagination URL.',
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'Meta Facebook pagination URL must use HTTPS.',
            );
        }

        if ($host !== self::GRAPH_API_HOST) {
            throw new RuntimeException(
                'Meta Facebook pagination URL has an unexpected host.',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(
                'Meta Facebook pagination URL must not contain URL credentials.',
            );
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new RuntimeException(
                'Meta Facebook pagination URL has an unexpected port.',
            );
        }

        if (isset($parts['fragment'])) {
            throw new RuntimeException(
                'Meta Facebook pagination URL must not contain a fragment.',
            );
        }
    }

    /**
     * Create or update a local comment.
     *
     * `updated` means a tracked persisted field changed.
     * `requires_ai` means the comment is new or its message changed.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     comment: FacebookComment,
     *     created: bool,
     *     updated: bool,
     *     requires_ai: bool
     * }
     */
    private function upsertComment(
        FacebookPost $post,
        array $payload,
    ): array {
        $facebookCommentId = $payload['id'] ?? null;

        if (
            ! is_string($facebookCommentId)
            || trim($facebookCommentId) === ''
        ) {
            throw new RuntimeException(
                'Cannot persist a Facebook comment without a Meta comment ID.',
            );
        }

        $facebookCommentId = trim($facebookCommentId);

        $message = $payload['message'] ?? '';

        if (! is_string($message)) {
            $message = '';
        }

        $message = trim($message);

        $authorName = data_get($payload, 'from.name');
        $authorFacebookId = data_get($payload, 'from.id');
        $parentCommentId = data_get($payload, 'parent.id');
        $permalinkUrl = $payload['permalink_url'] ?? null;

        $createdAt = $this->parseCreatedAt(
            $payload['created_time'] ?? null,
        );

        $likeCount = $payload['like_count'] ?? 0;

        if (! is_numeric($likeCount)) {
            $likeCount = 0;
        }

        $likeCount = max(0, (int) $likeCount);

        $parentCommentId = $this->nullableString($parentCommentId);
        $authorName = $this->nullableString($authorName);
        $authorFacebookId = $this->nullableString($authorFacebookId);
        $permalinkUrl = $this->nullableString($permalinkUrl);

        return DB::transaction(function () use (
            $post,
            $facebookCommentId,
            $message,
            $parentCommentId,
            $authorName,
            $authorFacebookId,
            $permalinkUrl,
            $createdAt,
            $likeCount,
            $payload,
        ): array {
            $comment = FacebookComment::query()
                ->where('facebook_comment_id', $facebookCommentId)
                ->lockForUpdate()
                ->first();

            $isNew = $comment === null;

            if ($comment === null) {
                $comment = new FacebookComment();
                $comment->facebook_post_id = $post->getKey();
                $comment->facebook_comment_id = $facebookCommentId;
            } elseif (
                (string) $comment->facebook_post_id
                !== (string) $post->getKey()
            ) {
                throw new RuntimeException(
                    'Meta comment ID is already associated with a different local post.',
                );
            }

            $oldMessage = $comment->message;

            $attributes = [
                'parent_comment_id' => $parentCommentId,
                'author_name' => $authorName,
                'author_facebook_id' => $authorFacebookId,
                'message' => $message,
                'permalink_url' => $permalinkUrl,
                'commented_at' => $createdAt,
                'like_count' => $likeCount,
                'raw_payload' => $payload,
            ];

            $changed = false;

            foreach ($attributes as $attribute => $value) {
                if ($comment->getAttribute($attribute) != $value) {
                    $changed = true;
                }

                $comment->setAttribute($attribute, $value);
            }

            $messageChanged = $isNew || $oldMessage !== $message;

            if ($isNew || $changed) {
                $comment->save();
            }

            return [
                'comment' => $comment,
                'created' => $isNew,
                'updated' => ! $isNew && $changed,
                'requires_ai' => $messageChanged && $message !== '',
            ];
        }, 3);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function parseCreatedAt(mixed $createdTime): ?string
    {
        if (
            ! is_string($createdTime)
            || trim($createdTime) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse($createdTime)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveNextPageUrl(array $response): ?string
    {
        $next = data_get($response, 'paging.next');

        if (! is_string($next) || trim($next) === '') {
            return null;
        }

        $next = trim($next);

        $this->validatePaginationUrl($next);

        return $next;
    }

    private function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return '[invalid-url]';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        /*
         * Never log the query string. Facebook pagination URLs can
         * contain access tokens and other sensitive parameters.
         */
        return sprintf(
            '%s://%s%s%s',
            $scheme,
            $host,
            $port,
            $path,
        );
    }
}
