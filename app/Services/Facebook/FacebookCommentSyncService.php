<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use App\Services\Http\HttpClientService;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

class FacebookCommentSyncService
{
    /**
     * Meta Graph API version.
     */
    private const GRAPH_API_VERSION = 'v26.0';

    /**
     * Number of comments requested per page.
     */
    private const PAGE_LIMIT = 100;

    /**
     * Maximum number of pagination pages processed in one sync.
     */
    private const MAX_PAGES = 100;

    /**
     * Allowed Meta Graph API host.
     */
    private const GRAPH_API_HOST = 'graph.facebook.com';

    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {
    }

    /**
     * Synchronize comments for a Facebook post.
     *
     * Returns internal Laravel comment UUIDs that require
     * AI analysis.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    public function sync(
        FacebookPost $post,
    ): array {
        $this->validatePost($post);

        $accessToken = $this->resolveAccessToken($post);

        $commentIdsForAnalysis = [];

        /*
         * Start from the first Meta comments endpoint.
         */
        $nextUrl = $this->buildCommentsUrl(
            (string) $post->facebook_post_id,
        );

        $page = 0;

        while ($nextUrl !== null) {
            $page++;

            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    sprintf(
                        'Maximum Facebook comment pagination limit of %d pages was reached for post %s.',
                        self::MAX_PAGES,
                        $post->facebook_post_id,
                    )
                );
            }

            /*
             * Every request must be authenticated.
             *
             * This is especially important for Meta paging.next URLs.
             */
            $response = $this->fetchComments(
                url: $nextUrl,
                accessToken: $accessToken,
                firstPage: $page === 1,
            );

            $data = $response['data'] ?? null;

            if (!is_array($data)) {
                throw new RuntimeException(
                    'Meta comments response does not contain a valid data array.'
                );
            }

            foreach ($data as $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $comment = $this->upsertComment(
                    post: $post,
                    payload: $payload,
                );

                if ($comment !== null) {
                    $commentIdsForAnalysis[] = (string) $comment->id;
                }
            }

            /*
             * Meta controls pagination.
             *
             * We do not reconstruct the cursor ourselves.
             */
            $nextUrl = $this->resolveNextPageUrl(
                $response,
            );
        }

        return array_values(
            array_unique(
                $commentIdsForAnalysis,
            ),
        );
    }

    /**
     * Validate the Facebook post.
     *
     * The post must already exist locally and contain
     * a valid Meta post ID.
     */
    private function validatePost(
        FacebookPost $post,
    ): void {
        if (!$post->exists) {
            throw new RuntimeException(
                'Facebook post must exist before comments can be synchronized.'
            );
        }

        if ($post->id === null) {
            throw new RuntimeException(
                'Facebook post must have an internal ID.'
            );
        }

        if (
            !is_string($post->facebook_post_id)
            || trim($post->facebook_post_id) === ''
        ) {
            throw new RuntimeException(
                'Facebook post does not have a valid Meta Facebook post ID.'
            );
        }
    }

    /**
     * Resolve the Facebook Page access token.
     *
     * FacebookPage stores the token in access_token_encrypted
     * and the model decrypts it through the encrypted cast.
     */
    private function resolveAccessToken(
        FacebookPost $post,
    ): string {
        $page = $post->facebookPage;

        if ($page === null) {
            throw new RuntimeException(
                'Facebook post is not associated with a Facebook page.'
            );
        }

        /*
         * IMPORTANT:
         *
         * Do not use:
         *
         *     $page->access_token
         *
         * unless that attribute actually exists in the model.
         *
         * Current application contract:
         *
         *     access_token_encrypted
         *
         * with Laravel encrypted cast.
         */
        $accessToken = $page->access_token_encrypted;

        if (
            !is_string($accessToken)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException(
                'Facebook page does not have a valid access token.'
            );
        }

        return trim($accessToken);
    }

    /**
     * Build the first Meta comments endpoint.
     */
    private function buildCommentsUrl(
        string $facebookPostId,
    ): string {
        return sprintf(
            'https://%s/%s/%s/comments',
            self::GRAPH_API_HOST,
            self::GRAPH_API_VERSION,
            rawurlencode($facebookPostId),
        );
    }

    /**
     * Build query parameters for a comments request.
     *
     * The access token is included on EVERY request.
     *
     * First request:
     * - fields
     * - limit
     * - access_token
     *
     * Pagination requests:
     * - access_token
     *
     * Meta's paging.next URL already contains the cursor and
     * other pagination parameters.
     *
     * @return array<string, string|int>
     */
    private function buildCommentsQuery(
        string $accessToken,
        bool $firstPage,
    ): array {
        /*
         * Authentication must be present on every page.
         */
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
     * Fetch one page of Facebook comments.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
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

            /*
             * Never log the access token.
             *
             * Also avoid logging the complete Meta paging URL because
             * it can contain a sensitive pagination cursor.
             */
            \Log::debug(
                'Fetching Facebook comments page.',
                [
                    'first_page' => $firstPage,
                    'query_keys' => array_keys($query),
                    'access_token_present' => true,
                    'url' => $this->sanitizeUrlForLog($url),
                ],
            );

            /*
             * IMPORTANT:
             *
             * HttpClientService must actually forward $query to
             * Laravel's HTTP client/Guzzle.
             *
             * For example:
             *
             *     Http::timeout($timeout)->get($url, $query)
             *
             * or:
             *
             *     ->withQueryParameters($query)->get($url)
             */
            $response = $this->httpClient->get(
                url: $url,
                query: $query,
                timeout: 30,
            );

            $json = $response->json();

            if (!is_array($json)) {
                throw new RuntimeException(
                    'Meta Graph API returned an invalid comments response.'
                );
            }

            /*
             * Meta can return an HTTP 200 response containing
             * an API-level error payload.
             */
            if (isset($json['error'])) {
                $message = data_get(
                    $json,
                    'error.message',
                );

                throw new RuntimeException(
                    is_string($message)
                        ? "Meta Graph API error: {$message}"
                        : 'Meta Graph API returned an unknown error.'
                );
            }

            return $json;
        } catch (RequestException $exception) {
            $message = $exception->response?->json(
                'error.message',
            );

            throw new RuntimeException(
                is_string($message)
                    ? "Meta Graph API error: {$message}"
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

    /**
     * Validate a Meta pagination URL before following it.
     *
     * This prevents accidentally following an arbitrary external URL
     * returned by malformed/untrusted data.
     */
    private function validatePaginationUrl(
        string $url,
    ): void {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException(
                'Meta returned an invalid Facebook pagination URL.'
            );
        }

        $scheme = strtolower(
            (string) ($parts['scheme'] ?? ''),
        );

        $host = strtolower(
            (string) ($parts['host'] ?? ''),
        );

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'Meta Facebook pagination URL must use HTTPS.'
            );
        }

        if ($host !== self::GRAPH_API_HOST) {
            throw new RuntimeException(
                'Meta Facebook pagination URL has an unexpected host.'
            );
        }
    }

    /**
     * Create or update one Facebook comment.
     *
     * Returns the comment only when it requires AI analysis.
     *
     * @param array<string, mixed> $payload
     */
    private function upsertComment(
        FacebookPost $post,
        array $payload,
    ): ?FacebookComment {
        $facebookCommentId = $payload['id'] ?? null;

        if (
            !is_string($facebookCommentId)
            || trim($facebookCommentId) === ''
        ) {
            return null;
        }

        $facebookCommentId = trim(
            $facebookCommentId,
        );

        /*
         * Message
         */
        $message = $payload['message'] ?? '';

        if (!is_string($message)) {
            $message = '';
        }

        $message = trim($message);

        /*
         * Author
         */
        $authorName = data_get(
            $payload,
            'from.name',
        );

        $authorFacebookId = data_get(
            $payload,
            'from.id',
        );

        /*
         * Parent comment
         */
        $parentCommentId = data_get(
            $payload,
            'parent.id',
        );

        /*
         * Permalink
         */
        $permalinkUrl = $payload['permalink_url'] ?? null;

        /*
         * Created timestamp
         */
        $createdAt = $this->parseCreatedAt(
            $payload['created_time'] ?? null,
        );

        /*
         * Like count
         */
        $likeCount = $payload['like_count'] ?? 0;

        if (
            !is_int($likeCount)
            && !is_numeric($likeCount)
        ) {
            $likeCount = 0;
        }

        $likeCount = max(
            0,
            (int) $likeCount,
        );

        /*
         * Find by Meta's unique comment ID.
         */
        $comment = FacebookComment::query()
            ->where(
                'facebook_comment_id',
                $facebookCommentId,
            )
            ->first();

        $isNew = $comment === null;

        /*
         * AI should run when:
         *
         * 1. Comment is new.
         * 2. Existing comment's message changed.
         */
        $messageChanged = $isNew
            || $comment->message !== $message;

        if ($comment === null) {
            $comment = new FacebookComment();

            /*
             * IMPORTANT:
             *
             * facebook_post_id in facebook_comments is the
             * INTERNAL Laravel UUID, not Meta's post ID.
             */
            $comment->facebook_post_id = $post->id;

            $comment->facebook_comment_id = $facebookCommentId;
        }

        /*
         * Parent
         */
        $comment->parent_comment_id = (
            is_string($parentCommentId)
            && trim($parentCommentId) !== ''
        )
            ? trim($parentCommentId)
            : null;

        /*
         * Author
         */
        $comment->author_name = (
            is_string($authorName)
            && trim($authorName) !== ''
        )
            ? trim($authorName)
            : null;

        $comment->author_facebook_id = (
            is_string($authorFacebookId)
            && trim($authorFacebookId) !== ''
        )
            ? trim($authorFacebookId)
            : null;

        /*
         * Message
         */
        $comment->message = $message;

        /*
         * Permalink
         */
        $comment->permalink_url = (
            is_string($permalinkUrl)
            && trim($permalinkUrl) !== ''
        )
            ? trim($permalinkUrl)
            : null;

        /*
         * Timestamp
         */
        $comment->commented_at = $createdAt;

        /*
         * Engagement
         */
        $comment->like_count = $likeCount;

        /*
         * Preserve complete Meta payload for auditing/debugging.
         */
        $comment->raw_payload = $payload;

        $comment->save();

        /*
         * Comments without message content cannot currently
         * be analyzed by the AI service.
         */
        if ($message === '') {
            return null;
        }

        /*
         * Existing unchanged comments do not need another AI
         * inference during the same synchronization.
         */
        if (!$messageChanged) {
            return null;
        }

        return $comment;
    }

    /**
     * Parse Meta created_time.
     */
    private function parseCreatedAt(
        mixed $createdTime,
    ): ?string {
        if (
            !is_string($createdTime)
            || trim($createdTime) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse(
                $createdTime,
            )->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve Meta's next pagination URL.
     *
     * @param array<string, mixed> $response
     */
    private function resolveNextPageUrl(
        array $response,
    ): ?string {
        $next = data_get(
            $response,
            'paging.next',
        );

        if (
            !is_string($next)
            || trim($next) === ''
        ) {
            return null;
        }

        return trim($next);
    }

    /**
     * Sanitize a Meta URL before writing it to logs.
     *
     * Access tokens and pagination cursors are sensitive and should
     * never be written to application logs.
     */
    private function sanitizeUrlForLog(
        string $url,
    ): string {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '[invalid-url]';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port'])
            ? ':' . $parts['port']
            : '';
        $path = $parts['path'] ?? '';

        return sprintf(
            '%s://%s%s%s',
            $scheme,
            $host,
            $port,
            $path,
        );
    }
}