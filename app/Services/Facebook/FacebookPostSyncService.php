<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostAttachment;
use App\Services\Http\HttpClientService;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookPostSyncService
{
    /*
    |--------------------------------------------------------------------------
    | Meta Graph API
    |--------------------------------------------------------------------------
    */

    private const GRAPH_API_VERSION = 'v26.0';

    private const GRAPH_API_HOST = 'graph.facebook.com';


    /*
    |--------------------------------------------------------------------------
    | Synchronization Limits
    |--------------------------------------------------------------------------
    */

    /**
     * Number of posts requested from Meta on the first page.
     *
     * Meta controls subsequent paging URLs.
     */
    private const META_POST_PAGE_LIMIT = 25;

    /**
     * Maximum pagination pages in one execution.
     */
    private const MAX_PAGES = 100;

    /**
     * Maximum posts processed in one execution.
     */
    private const MAX_POSTS_PER_SYNC = 10_000;

    /**
     * HTTP timeout in seconds.
     */
    private const HTTP_TIMEOUT = 30;


    /*
    |--------------------------------------------------------------------------
    | Sensitive Query Parameters
    |--------------------------------------------------------------------------
    */

    private const SENSITIVE_QUERY_PARAMETERS = [
        'access_token',
        'appsecret_proof',
    ];


    /*
    |--------------------------------------------------------------------------
    | Pagination Query Parameters
    |--------------------------------------------------------------------------
    */

    private const PAGINATION_QUERY_PARAMETERS = [
        'after',
        'before',
    ];


    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | Main Synchronization
    |--------------------------------------------------------------------------
    */

    /**
     * Synchronize Facebook posts for a page.
     *
     * Returns local FacebookPost UUIDs that require
     * downstream comment synchronization.
     *
     * @return array<int, string>
     *
     * @throws Throwable
     */
    public function sync(
        FacebookPage $page,
    ): array {
        Log::info(
            'FACEBOOK POST SYNC | STARTED',
            [
                'page_internal_id' => $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'max_posts_per_sync' => self::MAX_POSTS_PER_SYNC,
                'max_pages' => self::MAX_PAGES,
                'first_page_limit' => self::META_POST_PAGE_LIMIT,
            ],
        );

        try {
            /*
             * =========================================================
             * 1. VALIDATE PAGE
             * =========================================================
             */
            $this->validatePage($page);

            /*
             * =========================================================
             * 2. RESOLVE ACCESS TOKEN
             * =========================================================
             */
            $accessToken = $this->resolveAccessToken($page);

            Log::debug(
                'FACEBOOK POST SYNC | ACCESS TOKEN READY',
                [
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'token_present' => $accessToken !== '',
                    'token_length' => strlen($accessToken),
                    'token_expiration_available' =>
                        $page->hasTokenExpiration(),
                    'token_expired' =>
                        $page->hasExpiredToken(),
                ],
            );

            /*
             * =========================================================
             * 3. RESULT COLLECTION
             * =========================================================
             */
            $postIdsForCommentSync = [];

            /*
             * =========================================================
             * 4. FIRST META URL
             * =========================================================
             */
            $nextUrl = $this->buildPostsUrl(
                (string) $page->facebook_page_id,
            );

            Log::info(
                'FACEBOOK POST SYNC | FIRST PAGE URL CREATED',
                [
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'url' => $this->sanitizeUrlForLog($nextUrl),
                ],
            );

            /*
             * =========================================================
             * 5. PAGINATION STATE
             * =========================================================
             */
            $currentPage = 0;
            $totalPostsProcessed = 0;

            /**
             * Authentication parameters are removed from the
             * pagination identity, but after/before cursors remain.
             *
             * @var array<string, bool> $visitedPaginationUrls
             */
            $visitedPaginationUrls = [];

            $boundedCompletion = false;

            /*
             * =========================================================
             * 6. PAGINATION LOOP
             * =========================================================
             */
            while ($nextUrl !== null) {
                if ($currentPage >= self::MAX_PAGES) {
                    $boundedCompletion = true;

                    Log::warning(
                        'FACEBOOK POST SYNC | MAX PAGE LIMIT REACHED',
                        [
                            'page_internal_id' => $page->id,
                            'facebook_page_id' =>
                                $page->facebook_page_id,
                            'pagination_pages_processed' =>
                                $currentPage,
                            'max_pages' => self::MAX_PAGES,
                            'posts_processed' =>
                                $totalPostsProcessed,
                        ],
                    );

                    break;
                }

                /*
                 * -----------------------------------------------------
                 * Prepare Meta paging URL.
                 * -----------------------------------------------------
                 */
                $requestUrl =
                    $this->preparePaginationUrlForRequest(
                        $nextUrl,
                    );

                $requestPaginationInfo =
                    $this->getPaginationUrlInfo(
                        $requestUrl,
                    );

                Log::debug(
                    'FACEBOOK POST SYNC | REQUEST URL PREPARED',
                    [
                        'pagination_page' =>
                            $currentPage + 1,
                        'url' =>
                            $this->sanitizeUrlForLog(
                                $requestUrl,
                            ),
                        'has_after_cursor' =>
                            $requestPaginationInfo[
                                'has_after_cursor'
                            ],
                        'has_before_cursor' =>
                            $requestPaginationInfo[
                                'has_before_cursor'
                            ],
                        'cursor_type' =>
                            $requestPaginationInfo[
                                'cursor_type'
                            ],
                        'has_limit' =>
                            $requestPaginationInfo[
                                'has_limit'
                            ],
                        'limit' =>
                            $requestPaginationInfo[
                                'limit'
                            ],
                        'has_fields' =>
                            $requestPaginationInfo[
                                'has_fields'
                            ],
                        'query_parameter_count' =>
                            $requestPaginationInfo[
                                'query_parameter_count'
                            ],
                    ],
                );

                /*
                 * -----------------------------------------------------
                 * Repeated pagination detection.
                 * -----------------------------------------------------
                 */
                $normalizedPaginationUrl =
                    $this->normalizePaginationUrl(
                        $requestUrl,
                    );

                if (
                    isset(
                        $visitedPaginationUrls[
                            $normalizedPaginationUrl
                        ],
                    )
                ) {
                    Log::error(
                        'FACEBOOK POST SYNC | REPEATED PAGINATION URL DETECTED',
                        [
                            'page_internal_id' => $page->id,
                            'facebook_page_id' =>
                                $page->facebook_page_id,
                            'pagination_page' =>
                                $currentPage + 1,
                            'url' =>
                                $this->sanitizeUrlForLog(
                                    $requestUrl,
                                ),
                        ],
                    );

                    throw new RuntimeException(
                        'Meta returned a repeated pagination URL while synchronizing Facebook posts.',
                    );
                }

                $visitedPaginationUrls[
                    $normalizedPaginationUrl
                ] = true;

                $currentPage++;

                Log::info(
                    'FACEBOOK POST SYNC | PAGINATION PAGE STARTED',
                    [
                        'page_internal_id' => $page->id,
                        'facebook_page_id' =>
                            $page->facebook_page_id,
                        'pagination_page' =>
                            $currentPage,
                        'is_first_page' =>
                            $currentPage === 1,
                        'posts_processed_before_page' =>
                            $totalPostsProcessed,
                        'request_url' =>
                            $this->sanitizeUrlForLog(
                                $requestUrl,
                            ),
                        'has_after_cursor' =>
                            $requestPaginationInfo[
                                'has_after_cursor'
                            ],
                        'has_before_cursor' =>
                            $requestPaginationInfo[
                                'has_before_cursor'
                            ],
                        'cursor_type' =>
                            $requestPaginationInfo[
                                'cursor_type'
                            ],
                        'limit' =>
                            $requestPaginationInfo[
                                'limit'
                            ],
                        'has_fields' =>
                            $requestPaginationInfo[
                                'has_fields'
                            ],
                    ],
                );

                /*
                 * =====================================================
                 * 7. FETCH POSTS
                 * =====================================================
                 */
                $response = $this->fetchPosts(
                    url: $requestUrl,
                    accessToken: $accessToken,
                    firstPage: $currentPage === 1,
                );

                /*
                 * =====================================================
                 * 8. META ERROR
                 * =====================================================
                 */
                if (isset($response['error'])) {
                    $this->handleMetaApiError(
                        response: $response,
                        page: $page,
                        paginationPage: $currentPage,
                    );
                }

                /*
                 * =====================================================
                 * 9. VALIDATE DATA
                 * =====================================================
                 */
                $data = $response['data'] ?? null;

                if (!is_array($data)) {
                    Log::error(
                        'FACEBOOK POST SYNC | INVALID META DATA ARRAY',
                        [
                            'page_internal_id' => $page->id,
                            'facebook_page_id' =>
                                $page->facebook_page_id,
                            'pagination_page' =>
                                $currentPage,
                            'response_keys' =>
                                array_keys($response),
                        ],
                    );

                    throw new RuntimeException(
                        'Meta posts response does not contain a valid data array.',
                    );
                }

                /*
                 * =====================================================
                 * 10. EMPTY PAGE
                 * =====================================================
                 */
                if ($data === []) {
                    $unexpectedNextUrl =
                        $this->resolveNextPageUrl(
                            $response,
                        );

                    if ($unexpectedNextUrl !== null) {
                        Log::warning(
                            'FACEBOOK POST SYNC | EMPTY PAGE WITH NEXT URL',
                            [
                                'page_internal_id' =>
                                    $page->id,
                                'facebook_page_id' =>
                                    $page->facebook_page_id,
                                'pagination_page' =>
                                    $currentPage,
                                'next_url' =>
                                    $this->sanitizeUrlForLog(
                                        $unexpectedNextUrl,
                                    ),
                            ],
                        );
                    }

                    $nextUrl = null;

                    continue;
                }

                /*
                 * =====================================================
                 * 11. META PAGE RECEIVED
                 * =====================================================
                 */
                Log::info(
                    'FACEBOOK POST SYNC | META PAGE RECEIVED',
                    [
                        'page_internal_id' => $page->id,
                        'facebook_page_id' =>
                            $page->facebook_page_id,
                        'pagination_page' =>
                            $currentPage,
                        'post_count' =>
                            count($data),
                        'total_posts_processed_before_page' =>
                            $totalPostsProcessed,
                        'has_paging' =>
                            isset($response['paging']),
                        'has_next_page' =>
                            is_string(
                                data_get(
                                    $response,
                                    'paging.next',
                                ),
                            ),
                    ],
                );

                /*
                 * =====================================================
                 * 12. PROCESS POSTS
                 * =====================================================
                 */
                foreach ($data as $index => $payload) {
                    if (!is_array($payload)) {
                        Log::warning(
                            'FACEBOOK POST SYNC | INVALID POST PAYLOAD SKIPPED',
                            [
                                'page_internal_id' =>
                                    $page->id,
                                'facebook_page_id' =>
                                    $page->facebook_page_id,
                                'pagination_page' =>
                                    $currentPage,
                                'payload_index' =>
                                    $index,
                                'payload_type' =>
                                    get_debug_type(
                                        $payload,
                                    ),
                            ],
                        );

                        continue;
                    }

                    if (
                        $totalPostsProcessed
                        >= self::MAX_POSTS_PER_SYNC
                    ) {
                        $boundedCompletion = true;

                        Log::warning(
                            'FACEBOOK POST SYNC | MAX POST LIMIT REACHED',
                            [
                                'page_internal_id' =>
                                    $page->id,
                                'facebook_page_id' =>
                                    $page->facebook_page_id,
                                'pagination_page' =>
                                    $currentPage,
                                'posts_processed' =>
                                    $totalPostsProcessed,
                                'max_posts_per_sync' =>
                                    self::MAX_POSTS_PER_SYNC,
                            ],
                        );

                        break 2;
                    }

                    /*
                     * -------------------------------------------------
                     * Optional full payload logging.
                     * -------------------------------------------------
                     */
                    if (
                        $this->shouldLogFullMetaResponse()
                    ) {
                        Log::debug(
                            'FULL FACEBOOK POST PAYLOAD RECEIVED',
                            [
                                'page_internal_id' =>
                                    $page->id,
                                'facebook_page_id' =>
                                    $page->facebook_page_id,
                                'pagination_page' =>
                                    $currentPage,
                                'payload_index' =>
                                    $index,
                                'facebook_post_id' =>
                                    $payload['id'] ?? null,
                                'payload' =>
                                    $this->sanitizeMetaResponseForLog(
                                        $payload,
                                    ),
                            ],
                        );
                    }

                    /*
                     * -------------------------------------------------
                     * Create/update post.
                     * -------------------------------------------------
                     */
                    $post = $this->upsertPost(
                        page: $page,
                        payload: $payload,
                    );

                    if ($post === null) {
                        continue;
                    }

                    $totalPostsProcessed++;

                    /*
                     * -------------------------------------------------
                     * Comment synchronization.
                     * -------------------------------------------------
                     */
                    if (
                        $post->getAttribute(
                            '_requires_comment_sync',
                        ) === true
                    ) {
                        $postIdsForCommentSync[] =
                            (string) $post->id;
                    }

                    $post->offsetUnset(
                        '_requires_comment_sync',
                    );
                }

                /*
                 * =====================================================
                 * 13. NEXT PAGE
                 * =====================================================
                 */
                $nextUrl =
                    $this->resolveNextPageUrl(
                        $response,
                    );

                if ($nextUrl !== null) {
                    $nextPageInfo =
                        $this->getPaginationUrlInfo(
                            $nextUrl,
                        );

                    Log::info(
                        'FACEBOOK POST SYNC | NEXT PAGE RESOLVED',
                        [
                            'page_internal_id' =>
                                $page->id,
                            'facebook_page_id' =>
                                $page->facebook_page_id,
                            'current_page' =>
                                $currentPage,
                            'next_page_exists' =>
                                true,
                            'next_url' =>
                                $this->sanitizeUrlForLog(
                                    $nextUrl,
                                ),
                            'next_has_after_cursor' =>
                                $nextPageInfo[
                                    'has_after_cursor'
                                ],
                            'next_has_before_cursor' =>
                                $nextPageInfo[
                                    'has_before_cursor'
                                ],
                            'next_cursor_type' =>
                                $nextPageInfo[
                                    'cursor_type'
                                ],
                            'next_limit' =>
                                $nextPageInfo[
                                    'limit'
                                ],
                            'next_has_fields' =>
                                $nextPageInfo[
                                    'has_fields'
                                ],
                        ],
                    );
                } else {
                    Log::info(
                        'FACEBOOK POST SYNC | NO NEXT PAGE',
                        [
                            'page_internal_id' =>
                                $page->id,
                            'facebook_page_id' =>
                                $page->facebook_page_id,
                            'current_page' =>
                                $currentPage,
                        ],
                    );
                }

                Log::info(
                    'FACEBOOK POST SYNC | PAGINATION PAGE COMPLETED',
                    [
                        'page_internal_id' => $page->id,
                        'facebook_page_id' =>
                            $page->facebook_page_id,
                        'pagination_page' =>
                            $currentPage,
                        'posts_received_on_page' =>
                            count($data),
                        'total_posts_processed' =>
                            $totalPostsProcessed,
                        'posts_for_comment_sync_so_far' =>
                            count(
                                $postIdsForCommentSync,
                            ),
                        'has_next_page' =>
                            $nextUrl !== null,
                    ],
                );
            }

            /*
             * =========================================================
             * 14. REMOVE DUPLICATES
             * =========================================================
             */
            $postIdsForCommentSync = array_values(
                array_unique(
                    $postIdsForCommentSync,
                ),
            );

            /*
             * =========================================================
             * 15. LAST SYNC
             * =========================================================
             */
            $page->last_synced_at = now();
            $page->save();

            Log::info(
                'FACEBOOK POST SYNC | LAST SYNC TIMESTAMP UPDATED',
                [
                    'page_internal_id' =>
                        $page->id,
                    'facebook_page_id' =>
                        $page->facebook_page_id,
                    'last_synced_at' =>
                        $page->last_synced_at?->toISOString(),
                    'bounded_completion' =>
                        $boundedCompletion,
                ],
            );

            /*
             * =========================================================
             * 16. COMPLETED
             * =========================================================
             */
            Log::info(
                'FACEBOOK POST SYNC | COMPLETED',
                [
                    'page_internal_id' =>
                        $page->id,
                    'facebook_page_id' =>
                        $page->facebook_page_id,
                    'pagination_pages_processed' =>
                        $currentPage,
                    'total_posts_processed' =>
                        $totalPostsProcessed,
                    'posts_for_comment_sync_count' =>
                        count(
                            $postIdsForCommentSync,
                        ),
                    'bounded_completion' =>
                        $boundedCompletion,
                ],
            );

            return $postIdsForCommentSync;
        } catch (Throwable $exception) {
            Log::error(
                'FACEBOOK POST SYNC | FAILED',
                [
                    'page_internal_id' =>
                        $page->id,
                    'facebook_page_id' =>
                        $page->facebook_page_id,
                    'exception_class' =>
                        get_class($exception),
                    'exception_message' =>
                        $exception->getMessage(),
                    'exception_code' =>
                        $exception->getCode(),
                    'exception_file' =>
                        $exception->getFile(),
                    'exception_line' =>
                        $exception->getLine(),
                ],
            );

            throw $exception;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Page Validation
    |--------------------------------------------------------------------------
    */

    /**
     * @throws RuntimeException
     */
    private function validatePage(
        FacebookPage $page,
    ): void {
        if (!$page->exists) {
            throw new RuntimeException(
                'Facebook page must exist before posts can be synchronized.',
            );
        }

        if (
            !is_string($page->id)
            || trim($page->id) === ''
        ) {
            throw new RuntimeException(
                'Facebook page must have an internal ID.',
            );
        }

        if (
            !is_string($page->facebook_page_id)
            || trim($page->facebook_page_id) === ''
        ) {
            throw new RuntimeException(
                'Facebook page does not have a valid Meta Facebook Page ID.',
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Access Token
    |--------------------------------------------------------------------------
    */

    /**
     * @throws RuntimeException
     */
    private function resolveAccessToken(
        FacebookPage $page,
    ): string {
        $accessToken =
            $page->access_token_encrypted;

        if (
            !is_string($accessToken)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException(
                'Facebook page does not have a valid access token.',
            );
        }

        if ($page->hasExpiredToken()) {
            Log::error(
                'FACEBOOK POST SYNC | ACCESS TOKEN EXPIRED',
                [
                    'page_internal_id' =>
                        $page->id,
                    'facebook_page_id' =>
                        $page->facebook_page_id,
                    'token_expired_at' =>
                        $page
                            ->page_access_token_expires_at
                            ?->toISOString(),
                ],
            );

            throw new RuntimeException(
                'Facebook page access token has expired. Please reconnect the Facebook Page.',
            );
        }

        return trim($accessToken);
    }


    /*
    |--------------------------------------------------------------------------
    | Meta URL
    |--------------------------------------------------------------------------
    */

    private function buildPostsUrl(
        string $facebookPageId,
    ): string {
        return sprintf(
            'https://%s/%s/%s/posts',
            self::GRAPH_API_HOST,
            self::GRAPH_API_VERSION,
            rawurlencode($facebookPageId),
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Fetch Posts
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function fetchPosts(
        string $url,
        string $accessToken,
        bool $firstPage,
    ): array {
        $query = [
            'access_token' => $accessToken,
        ];

        /*
         * Only the first request explicitly specifies fields and limit.
         *
         * Meta's paging.next controls subsequent requests.
         */
        if ($firstPage) {
            $query['fields'] = implode(',', [
                'id',
                'message',
                'created_time',
                'permalink_url',
                'likes.summary(true)',
                'comments.summary(true)',
                'shares',
                'attachments',
            ]);

            $query['limit'] =
                self::META_POST_PAGE_LIMIT;
        }

        $urlInfo =
            $this->getPaginationUrlInfo(
                $url,
            );

        Log::info(
            'FACEBOOK POST SYNC | HTTP REQUEST TO META',
            [
                'first_page' =>
                    $firstPage,
                'url' =>
                    $this->sanitizeUrlForLog(
                        $url,
                    ),
                'url_has_after_cursor' =>
                    $urlInfo[
                        'has_after_cursor'
                    ],
                'url_has_before_cursor' =>
                    $urlInfo[
                        'has_before_cursor'
                    ],
                'url_cursor_type' =>
                    $urlInfo[
                        'cursor_type'
                    ],
                'url_limit' =>
                    $urlInfo[
                        'limit'
                    ],
                'url_has_fields' =>
                    $urlInfo[
                        'has_fields'
                    ],
                'url_query_parameter_count' =>
                    $urlInfo[
                        'query_parameter_count'
                    ],
                'request_query_contains_access_token' =>
                    isset(
                        $query['access_token'],
                    ),
                'request_query_limit' =>
                    $query['limit'] ?? null,
                'request_query_fields_present' =>
                    isset(
                        $query['fields'],
                    ),
            ],
        );

        try {
            /*
             * HttpClientService MUST preserve query parameters already
             * contained in $url.
             */
            $response = $this->httpClient->get(
                url: $url,
                query: $query,
                timeout: self::HTTP_TIMEOUT,
            );

            if (
                method_exists(
                    $response,
                    'failed',
                )
                && $response->failed()
            ) {
                $errorMessage =
                    method_exists(
                        $response,
                        'json',
                    )
                        ? $response->json(
                            'error.message',
                        )
                        : null;

                Log::error(
                    'FACEBOOK POST SYNC | META HTTP FAILURE',
                    [
                        'url' =>
                            $this->sanitizeUrlForLog(
                                $url,
                            ),
                        'status' =>
                            method_exists(
                                $response,
                                'status',
                            )
                                ? $response->status()
                                : null,
                        'error_message' =>
                            is_string(
                                $errorMessage,
                            )
                                ? $errorMessage
                                : null,
                    ],
                );

                throw new RuntimeException(
                    is_string($errorMessage)
                        ? "Meta Graph API error: {$errorMessage}"
                        : 'Meta Graph API returned an HTTP error.',
                );
            }

            $json = $response->json();

            if (!is_array($json)) {
                throw new RuntimeException(
                    'Meta Graph API returned an invalid posts response.',
                );
            }

            if (
                $this->shouldLogFullMetaResponse()
            ) {
                Log::debug(
                    'FULL FACEBOOK GRAPH API JSON RESPONSE',
                    [
                        'url' =>
                            $this->sanitizeUrlForLog(
                                $url,
                            ),
                        'response' =>
                            $this->sanitizeMetaResponseForLog(
                                $json,
                            ),
                    ],
                );
            } else {
                Log::debug(
                    'FACEBOOK POST SYNC | META JSON PARSED',
                    [
                        'response_keys' =>
                            array_keys($json),
                        'data_count' =>
                            is_array(
                                $json['data'] ?? null,
                            )
                                ? count(
                                    $json['data'],
                                )
                                : null,
                        'has_paging' =>
                            isset(
                                $json['paging'],
                            ),
                        'has_next_page' =>
                            is_string(
                                data_get(
                                    $json,
                                    'paging.next',
                                ),
                            ),
                        'has_error' =>
                            isset(
                                $json['error'],
                            ),
                        'error_message' =>
                            data_get(
                                $json,
                                'error.message',
                            ),
                        'error_code' =>
                            data_get(
                                $json,
                                'error.code',
                            ),
                        'error_type' =>
                            data_get(
                                $json,
                                'error.type',
                            ),
                    ],
                );
            }

            return $json;
        } catch (RequestException $exception) {
            $message =
                $exception
                    ->response
                    ?->json(
                        'error.message',
                    );

            Log::error(
                'FACEBOOK POST SYNC | REQUEST EXCEPTION',
                [
                    'url' =>
                        $this->sanitizeUrlForLog(
                            $url,
                        ),
                    'status' =>
                        $exception
                            ->response
                            ?->status(),
                    'error_message' =>
                        is_string($message)
                            ? $message
                            : null,
                    'exception_class' =>
                        get_class($exception),
                    'exception_message' =>
                        $exception->getMessage(),
                ],
            );

            throw new RuntimeException(
                is_string($message)
                    ? "Meta Graph API error: {$message}"
                    : 'Meta Graph API post request failed.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            Log::error(
                'FACEBOOK POST SYNC | REQUEST FAILED',
                [
                    'url' =>
                        $this->sanitizeUrlForLog(
                            $url,
                        ),
                    'exception_class' =>
                        get_class($exception),
                    'exception_message' =>
                        $exception->getMessage(),
                    'exception_code' =>
                        $exception->getCode(),
                ],
            );

            if (
                $exception instanceof RuntimeException
            ) {
                throw $exception;
            }

            throw new RuntimeException(
                'Failed to retrieve Facebook posts from Meta.',
                previous: $exception,
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Post Upsert
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a Facebook post.
     *
     * @param array<string, mixed> $payload
     */
    private function upsertPost(
        FacebookPage $page,
        array $payload,
    ): ?FacebookPost {
        $facebookPostId =
            $payload['id'] ?? null;

        if (
            !is_string($facebookPostId)
            || trim($facebookPostId) === ''
        ) {
            Log::warning(
                'FACEBOOK POST SYNC | POST HAS NO VALID META ID',
                [
                    'page_internal_id' =>
                        $page->id,
                    'facebook_page_id' =>
                        $page->facebook_page_id,
                    'payload_keys' =>
                        array_keys($payload),
                ],
            );

            return null;
        }

        $facebookPostId =
            trim($facebookPostId);

        $post =
            FacebookPost::query()
                ->where(
                    'facebook_post_id',
                    $facebookPostId,
                )
                ->first();

        $isNew =
            $post === null;

        /*
         * Preserve previous values when Meta omits fields.
         */
        $previousMessage =
            $post?->message;

        $previousPermalink =
            $post?->permalink_url;

        $previousPublishedAt =
            $post?->published_at;

        $previousLikeCount =
            $post?->like_count;

        $previousCommentCount =
            $post?->comment_count;

        $previousShareCount =
            $post?->share_count;

        /*
         * Message.
         */
        $messageFieldPresent =
            array_key_exists(
                'message',
                $payload,
            );

        $message =
            $messageFieldPresent
                ? $this->normalizeNullableString(
                    $payload['message'] ?? null,
                )
                : $previousMessage;

        /*
         * Permalink.
         */
        $permalinkFieldPresent =
            array_key_exists(
                'permalink_url',
                $payload,
            );

        $permalinkUrl =
            $permalinkFieldPresent
                ? $this->normalizeNullableString(
                    $payload['permalink_url'] ?? null,
                )
                : $previousPermalink;

        /*
         * Published time.
         */
        $createdTimeFieldPresent =
            array_key_exists(
                'created_time',
                $payload,
            );

        $publishedAt =
            $createdTimeFieldPresent
                ? $this->parseDateTime(
                    $payload['created_time'] ?? null,
                )
                : $previousPublishedAt;

        /*
         * Engagement.
         */
        $likeCount =
            $this->extractLikeCount(
                $payload,
            );

        $commentCount =
            $this->extractCommentCount(
                $payload,
            );

        $shareCount =
            $this->extractShareCount(
                $payload,
            );

        $storedLikeCount =
            $likeCount !== null
                ? $likeCount
                : ($isNew
                    ? 0
                    : ($previousLikeCount ?? 0));

        $storedCommentCount =
            $commentCount !== null
                ? $commentCount
                : ($isNew
                    ? 0
                    : ($previousCommentCount ?? 0));

        $storedShareCount =
            $shareCount !== null
                ? $shareCount
                : ($isNew
                    ? 0
                    : ($previousShareCount ?? 0));

        /*
         * Change detection.
         */
        $messageChanged =
            $isNew
            || (
                $messageFieldPresent
                && $previousMessage !== $message
            );

        $commentsChanged =
            $isNew
            || (
                $commentCount !== null
                && $previousCommentCount !== $commentCount
            );

        $commentSyncReason = null;

        if ($isNew) {
            $commentSyncReason = 'new_post';
        } elseif ($messageChanged) {
            $commentSyncReason = 'message_changed';
        } elseif ($commentsChanged) {
            $commentSyncReason = 'comment_count_changed';
        }

        /*
         * Create post.
         */
        if ($post === null) {
            $post = new FacebookPost();

            /*
             * IMPORTANT:
             *
             * facebook_posts.facebook_page_id is the internal
             * FacebookPage UUID.
             */
            $post->facebook_page_id =
                $page->id;

            /*
             * facebook_posts.facebook_post_id is the external
             * Meta Post ID.
             */
            $post->facebook_post_id =
                $facebookPostId;
        }

        /*
         * Persist the post.
         */
        DB::transaction(
            function () use (
                $post,
                $message,
                $permalinkUrl,
                $publishedAt,
                $storedLikeCount,
                $storedCommentCount,
                $storedShareCount,
                $payload,
            ): void {
                $post->message =
                    $message;

                $post->permalink_url =
                    $permalinkUrl;

                $post->published_at =
                    $publishedAt;

                $post->like_count =
                    $storedLikeCount;

                $post->comment_count =
                    $storedCommentCount;

                $post->share_count =
                    $storedShareCount;

                $post->raw_payload =
                    $payload;

                $post->save();
            },
        );

        /*
         * =============================================================
         * ATTACHMENTS
         * =============================================================
         *
         * This happens AFTER the FacebookPost exists.
         *
         * The attachment synchronization is intentionally separate
         * from the post transaction so that a large attachment tree
         * does not unnecessarily extend the post transaction.
         */
        $this->syncPostAttachments(
            facebookPost: $post,
            payload: $payload,
        );

        /*
         * Optional DB verification.
         */
        if ($this->shouldVerifyPersistence()) {
            $post->refresh();

            $correct =
                $post->like_count === $storedLikeCount
                && $post->comment_count ===
                    $storedCommentCount
                && $post->share_count ===
                    $storedShareCount;

            if (!$correct) {
                Log::error(
                    'FACEBOOK POST SYNC | PERSISTENCE VERIFICATION FAILED',
                    [
                        'facebook_page_id' =>
                            $page->facebook_page_id,
                        'facebook_post_id' =>
                            $facebookPostId,
                        'local_post_id' =>
                            $post->id,
                    ],
                );

                throw new RuntimeException(
                    'Facebook post engagement values were not persisted correctly.',
                );
            }
        }

        /*
         * Runtime-only flag.
         */
        $post->setAttribute(
            '_requires_comment_sync',
            $commentSyncReason !== null,
        );

        Log::info(
            'FACEBOOK POST SYNC | POST SAVED',
            [
                'page_internal_id' =>
                    $page->id,
                'facebook_page_id' =>
                    $page->facebook_page_id,
                'facebook_post_id' =>
                    $facebookPostId,
                'local_post_id' =>
                    $post->id,
                'is_new' =>
                    $isNew,
                'message_changed' =>
                    $messageChanged,
                'comments_changed' =>
                    $commentsChanged,
                'comment_sync_reason' =>
                    $commentSyncReason,
                'published_at' =>
                    $post->published_at?->toISOString(),
                'stored_like_count' =>
                    $storedLikeCount,
                'stored_comment_count' =>
                    $storedCommentCount,
                'stored_share_count' =>
                    $storedShareCount,
            ],
        );

        return $post;
    }


    /*
    |--------------------------------------------------------------------------
    | Attachment Synchronization
    |--------------------------------------------------------------------------
    */

    /**
     * Synchronize all attachments belonging to a Facebook post.
     *
     * Important semantics:
     *
     * 1. attachments field absent:
     *      Existing attachments are preserved.
     *
     * 2. attachments.data = []:
     *      Existing attachments are deleted.
     *
     * 3. attachments.data contains items:
     *      Existing attachment tree is replaced.
     *
     * Meta does not provide a consistently reliable attachment-level
     * ID, so replacing the attachment tree per post is intentional.
     *
     * @param array<string, mixed> $payload
     */
    private function syncPostAttachments(
        FacebookPost $facebookPost,
        array $payload,
    ): void {
        /*
         * -------------------------------------------------------------
         * Meta did not return the attachments field.
         *
         * Do NOT assume zero attachments.
         * -------------------------------------------------------------
         */
        if (
            !array_key_exists(
                'attachments',
                $payload,
            )
        ) {
            return;
        }

        $attachments =
            data_get(
                $payload,
                'attachments.data',
            );

        /*
         * -------------------------------------------------------------
         * Explicitly empty attachments.
         * -------------------------------------------------------------
         */
        if (
            !is_array($attachments)
            || $attachments === []
        ) {
            $facebookPost->attachments()->delete();

            Log::debug(
                'FACEBOOK POST SYNC | ATTACHMENTS CLEARED',
                [
                    'facebook_post_id' =>
                        $facebookPost->id,
                    'attachment_count' =>
                        0,
                ],
            );

            return;
        }

        /*
         * -------------------------------------------------------------
         * Replace existing attachment tree.
         * -------------------------------------------------------------
         */
        DB::transaction(
            function () use (
                $facebookPost,
                $attachments,
            ): void {
                /*
                 * Because parent_attachment_id has ON DELETE CASCADE,
                 * deleting the top-level records also removes nested
                 * child attachments.
                 */
                $facebookPost
                    ->attachments()
                    ->delete();

                foreach (
                    $attachments as $position => $attachment
                ) {
                    if (!is_array($attachment)) {
                        continue;
                    }

                    $this->createPostAttachment(
                        facebookPost: $facebookPost,
                        attachment: $attachment,
                        position: (int) $position,
                    );
                }
            },
        );

        Log::debug(
            'FACEBOOK POST SYNC | ATTACHMENTS NORMALIZED',
            [
                'facebook_post_id' =>
                    $facebookPost->id,
                'attachment_count' =>
                    count($attachments),
            ],
        );
    }


    /**
     * Create an attachment and recursively create nested
     * subattachments.
     *
     * @param array<string, mixed> $attachment
     */
    private function createPostAttachment(
        FacebookPost $facebookPost,
        array $attachment,
        int $position,
        ?string $parentAttachmentId = null,
    ): FacebookPostAttachment {
        $mediaImage =
            data_get(
                $attachment,
                'media.image',
            );

        $type =
            $this->normalizeNullableString(
                data_get(
                    $attachment,
                    'type',
                ),
            );

        $mediaType =
            $this->resolveAttachmentMediaType(
                $attachment,
            );

        $attachmentModel =
            FacebookPostAttachment::query()->create([
                'facebook_post_id' =>
                    $facebookPost->id,

                'parent_attachment_id' =>
                    $parentAttachmentId,

                'type' =>
                    $type,

                'media_type' =>
                    $mediaType,

                'url' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'url',
                        ),
                    ),

                'media_url' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'media.source',
                        ),
                    ),

                'thumbnail_url' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'media.image.src',
                        ),
                    ),

                'target_id' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'target.id',
                        ),
                    ),

                'target_url' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'target.url',
                        ),
                    ),

                'title' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'title',
                        ),
                    ),

                'description' =>
                    $this->normalizeNullableString(
                        data_get(
                            $attachment,
                            'description',
                        ),
                    ),

                'width' =>
                    $this->normalizeNullableInteger(
                        data_get(
                            $mediaImage,
                            'width',
                        ),
                    ),

                'height' =>
                    $this->normalizeNullableInteger(
                        data_get(
                            $mediaImage,
                            'height',
                        ),
                    ),

                'position' =>
                    $position,

                'raw_payload' =>
                    $attachment,
            ]);

        /*
         * =============================================================
         * NESTED SUBATTACHMENTS
         * =============================================================
         */
        $subattachments =
            data_get(
                $attachment,
                'subattachments.data',
            );

        if (is_array($subattachments)) {
            foreach (
                $subattachments as $childPosition => $childAttachment
            ) {
                if (!is_array($childAttachment)) {
                    continue;
                }

                $this->createPostAttachment(
                    facebookPost: $facebookPost,
                    attachment: $childAttachment,
                    position: (int) $childPosition,
                    parentAttachmentId:
                        $attachmentModel->id,
                );
            }
        }

        return $attachmentModel;
    }


    /**
     * Resolve application-level media type.
     *
     * @param array<string, mixed> $attachment
     */
    private function resolveAttachmentMediaType(
        array $attachment,
    ): string {
        $type =
            strtolower(
                trim(
                    (string) data_get(
                        $attachment,
                        'type',
                    ),
                ),
            );

        /*
         * -------------------------------------------------------------
         * Album
         * -------------------------------------------------------------
         */
        if ($type === 'album') {
            return 'album';
        }

        /*
         * -------------------------------------------------------------
         * Video
         * -------------------------------------------------------------
         *
         * Handles:
         *
         * video_inline
         * video_direct_response
         * future video_* types
         */
        if (
            str_contains(
                $type,
                'video',
            )
            || data_get(
                $attachment,
                'media.source',
            ) !== null
        ) {
            return 'video';
        }

        /*
         * -------------------------------------------------------------
         * Image / Photo
         * -------------------------------------------------------------
         */
        if (
            $type === 'photo'
            || data_get(
                $attachment,
                'media.image.src',
            ) !== null
        ) {
            return 'image';
        }

        /*
         * -------------------------------------------------------------
         * Shared link
         * -------------------------------------------------------------
         */
        if ($type === 'share') {
            return 'link';
        }

        /*
         * -------------------------------------------------------------
         * Unavailable content
         * -------------------------------------------------------------
         */
        if ($type === 'native_templates') {
            $title =
                strtolower(
                    (string) data_get(
                        $attachment,
                        'title',
                    ),
                );

            $description =
                strtolower(
                    (string) data_get(
                        $attachment,
                        'description',
                    ),
                );

            if (
                str_contains(
                    $title,
                    "content isn't available",
                )
                || str_contains(
                    $description,
                    "content isn't available",
                )
                || str_contains(
                    $title,
                    'content isn\'t available',
                )
                || str_contains(
                    $description,
                    'content isn\'t available',
                )
            ) {
                return 'unavailable';
            }
        }

        return 'unknown';
    }


    /*
    |--------------------------------------------------------------------------
    | Attribute Normalization
    |--------------------------------------------------------------------------
    */

    private function normalizeNullableString(
        mixed $value,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }


    private function normalizeNullableInteger(
        mixed $value,
    ): ?int {
        if (
            $value === null
            || $value === ''
            || !is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }


    /*
    |--------------------------------------------------------------------------
    | Engagement Extraction
    |--------------------------------------------------------------------------
    */

    private function extractLikeCount(
        array $payload,
    ): ?int {
        if (
            !array_key_exists(
                'likes',
                $payload,
            )
        ) {
            return null;
        }

        $count =
            data_get(
                $payload,
                'likes.summary.total_count',
            );

        if (
            !is_int($count)
            && !is_numeric($count)
        ) {
            return null;
        }

        return max(
            0,
            (int) $count,
        );
    }


    private function extractCommentCount(
        array $payload,
    ): ?int {
        if (
            !array_key_exists(
                'comments',
                $payload,
            )
        ) {
            return null;
        }

        $count =
            data_get(
                $payload,
                'comments.summary.total_count',
            );

        if (
            !is_int($count)
            && !is_numeric($count)
        ) {
            return null;
        }

        return max(
            0,
            (int) $count,
        );
    }


    private function extractShareCount(
        array $payload,
    ): ?int {
        if (
            !array_key_exists(
                'shares',
                $payload,
            )
        ) {
            return null;
        }

        $count =
            data_get(
                $payload,
                'shares.count',
            );

        if (
            !is_int($count)
            && !is_numeric($count)
        ) {
            return null;
        }

        return max(
            0,
            (int) $count,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Date Parsing
    |--------------------------------------------------------------------------
    */

    private function parseDateTime(
        mixed $value,
    ): ?string {
        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse(
                $value,
            )->toDateTimeString();
        } catch (Throwable $exception) {
            Log::warning(
                'FACEBOOK POST SYNC | TIMESTAMP PARSE FAILED',
                [
                    'value_type' =>
                        get_debug_type($value),
                    'exception_class' =>
                        get_class($exception),
                    'exception_message' =>
                        $exception->getMessage(),
                ],
            );

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $response
     *
     * @throws RuntimeException
     */
    private function resolveNextPageUrl(
        array $response,
    ): ?string {
        $next =
            data_get(
                $response,
                'paging.next',
            );

        if (
            !is_string($next)
            || trim($next) === ''
        ) {
            return null;
        }

        $next = trim($next);

        $parsed =
            parse_url($next);

        if (
            $parsed === false
            || !isset(
                $parsed['scheme'],
                $parsed['host'],
            )
            || strtolower(
                (string) $parsed['scheme'],
            ) !== 'https'
            || !is_string(
                $parsed['host'],
            )
            || strtolower(
                trim(
                    $parsed['host'],
                ),
            ) !== self::GRAPH_API_HOST
        ) {
            throw new RuntimeException(
                'Meta returned an invalid Facebook posts pagination URL.',
            );
        }

        $nextInfo =
            $this->getPaginationUrlInfo(
                $next,
            );

        Log::info(
            'FACEBOOK POST SYNC | META NEXT CURSOR RECEIVED',
            [
                'next_url' =>
                    $this->sanitizeUrlForLog(
                        $next,
                    ),
                'has_after_cursor' =>
                    $nextInfo[
                        'has_after_cursor'
                    ],
                'has_before_cursor' =>
                    $nextInfo[
                        'has_before_cursor'
                    ],
                'cursor_type' =>
                    $nextInfo[
                        'cursor_type'
                    ],
                'limit' =>
                    $nextInfo[
                        'limit'
                    ],
                'has_fields' =>
                    $nextInfo[
                        'has_fields'
                    ],
            ],
        );

        return $next;
    }


    /**
     * Prepare a Meta pagination URL for an actual request.
     *
     * Authentication parameters are removed.
     *
     * Pagination cursors remain intact.
     *
     * @throws RuntimeException
     */
    private function preparePaginationUrlForRequest(
        string $url,
    ): string {
        $parsed =
            parse_url($url);

        if ($parsed === false) {
            throw new RuntimeException(
                'Unable to parse Meta pagination URL.',
            );
        }

        $scheme =
            $parsed['scheme'] ?? null;

        $host =
            $parsed['host'] ?? null;

        if (
            !is_string($scheme)
            || !is_string($host)
        ) {
            throw new RuntimeException(
                'Meta pagination URL is missing required URL components.',
            );
        }

        if (
            strtolower($scheme) !== 'https'
            || strtolower($host) !== self::GRAPH_API_HOST
        ) {
            throw new RuntimeException(
                'Meta returned an unexpected pagination URL host or scheme.',
            );
        }

        $prepared =
            'https://'
            . self::GRAPH_API_HOST;

        if (isset($parsed['port'])) {
            $port = $parsed['port'];

            if (
                is_int($port)
                || (
                    is_string($port)
                    && ctype_digit($port)
                )
            ) {
                $prepared .= ':' . $port;
            }
        }

        $prepared .=
            (string) (
                $parsed['path'] ?? ''
            );

        $query = [];

        if (
            isset($parsed['query'])
            && is_string($parsed['query'])
            && $parsed['query'] !== ''
        ) {
            parse_str(
                $parsed['query'],
                $query,
            );

            foreach (
                self::SENSITIVE_QUERY_PARAMETERS
                as $parameter
            ) {
                unset(
                    $query[$parameter],
                );
            }
        }

        if ($query !== []) {
            $prepared .=
                '?'
                . http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                );
        }

        /*
         * Critical protection:
         *
         * Never silently lose Meta's cursor.
         */
        $beforeInfo =
            $this->getPaginationUrlInfo(
                $url,
            );

        $afterInfo =
            $this->getPaginationUrlInfo(
                $prepared,
            );

        if (
            $beforeInfo['has_after_cursor']
            && !$afterInfo['has_after_cursor']
        ) {
            throw new RuntimeException(
                'Facebook pagination after cursor was lost while preparing the request URL.',
            );
        }

        if (
            $beforeInfo['has_before_cursor']
            && !$afterInfo['has_before_cursor']
        ) {
            throw new RuntimeException(
                'Facebook pagination before cursor was lost while preparing the request URL.',
            );
        }

        return $prepared;
    }


    /**
     * Normalize pagination URL for repeated-page detection.
     */
    private function normalizePaginationUrl(
        string $url,
    ): string {
        $parsed =
            parse_url($url);

        if ($parsed === false) {
            return $url;
        }

        $scheme =
            strtolower(
                (string) (
                    $parsed['scheme'] ?? ''
                ),
            );

        $host =
            strtolower(
                (string) (
                    $parsed['host'] ?? ''
                ),
            );

        $path =
            (string) (
                $parsed['path'] ?? ''
            );

        $query = [];

        if (
            isset($parsed['query'])
            && is_string(
                $parsed['query'],
            )
        ) {
            parse_str(
                $parsed['query'],
                $query,
            );
        }

        foreach (
            self::SENSITIVE_QUERY_PARAMETERS
            as $parameter
        ) {
            unset(
                $query[$parameter],
            );
        }

        if ($query !== []) {
            ksort($query);
        }

        $normalized =
            $scheme
            . '://'
            . $host
            . $path;

        if ($query !== []) {
            $normalized .=
                '?'
                . http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                );
        }

        return $normalized;
    }


    /**
     * @return array{
     *     has_after_cursor: bool,
     *     has_before_cursor: bool,
     *     cursor_type: ?string,
     *     has_limit: bool,
     *     limit: ?string,
     *     has_fields: bool,
     *     query_parameter_count: int
     * }
     */
    private function getPaginationUrlInfo(
        string $url,
    ): array {
        $parsed =
            parse_url($url);

        if (
            $parsed === false
            || !isset($parsed['query'])
            || !is_string($parsed['query'])
            || $parsed['query'] === ''
        ) {
            return [
                'has_after_cursor' => false,
                'has_before_cursor' => false,
                'cursor_type' => null,
                'has_limit' => false,
                'limit' => null,
                'has_fields' => false,
                'query_parameter_count' => 0,
            ];
        }

        $query = [];

        parse_str(
            $parsed['query'],
            $query,
        );

        $hasAfter =
            isset($query['after'])
            && is_string($query['after'])
            && trim($query['after']) !== '';

        $hasBefore =
            isset($query['before'])
            && is_string($query['before'])
            && trim($query['before']) !== '';

        $cursorType = null;

        if ($hasAfter && $hasBefore) {
            $cursorType = 'after_and_before';
        } elseif ($hasAfter) {
            $cursorType = 'after';
        } elseif ($hasBefore) {
            $cursorType = 'before';
        }

        $limit = null;

        if (
            isset($query['limit'])
            && (
                is_string($query['limit'])
                || is_numeric($query['limit'])
            )
        ) {
            $limit = (string) $query['limit'];
        }

        return [
            'has_after_cursor' =>
                $hasAfter,
            'has_before_cursor' =>
                $hasBefore,
            'cursor_type' =>
                $cursorType,
            'has_limit' =>
                array_key_exists(
                    'limit',
                    $query,
                ),
            'limit' =>
                $limit,
            'has_fields' =>
                array_key_exists(
                    'fields',
                    $query,
                ),
            'query_parameter_count' =>
                count($query),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $response
     *
     * @throws RuntimeException
     */
    private function handleMetaApiError(
        array $response,
        FacebookPage $page,
        int $paginationPage,
    ): void {
        $message =
            data_get(
                $response,
                'error.message',
            );

        $type =
            data_get(
                $response,
                'error.type',
            );

        $code =
            data_get(
                $response,
                'error.code',
            );

        $subcode =
            data_get(
                $response,
                'error.error_subcode',
            );

        Log::error(
            'FACEBOOK POST SYNC | META ERROR PAYLOAD',
            [
                'page_internal_id' =>
                    $page->id,
                'facebook_page_id' =>
                    $page->facebook_page_id,
                'pagination_page' =>
                    $paginationPage,
                'error_type' =>
                    $type,
                'error_code' =>
                    $code,
                'error_subcode' =>
                    $subcode,
                'error_message' =>
                    $message,
            ],
        );

        if (
            is_string($message)
            && trim($message) !== ''
        ) {
            throw new RuntimeException(
                "Meta Graph API error: {$message}",
            );
        }

        throw new RuntimeException(
            'Meta Graph API returned an error while retrieving Facebook posts.',
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    private function shouldLogFullMetaResponse(): bool
    {
        return (bool) config(
            'services.facebook.log_full_response',
            false,
        );
    }


    private function shouldVerifyPersistence(): bool
    {
        return (bool) config(
            'services.facebook.verify_post_persistence',
            false,
        );
    }


    /**
     * Sanitize a Meta response before logging.
     *
     * Authentication values and pagination cursors are never logged.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function sanitizeMetaResponseForLog(
        array $response,
    ): array {
        $sanitized =
            $this->sanitizeMetaValueForLog(
                $response,
            );

        return is_array($sanitized)
            ? $sanitized
            : [];
    }


    private function sanitizeMetaValueForLog(
        mixed $value,
        ?string $parentKey = null,
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $keyString =
                is_string($key)
                    ? strtolower($key)
                    : (string) $key;

            /*
             * Authentication secrets.
             */
            if (
                in_array(
                    $keyString,
                    self::SENSITIVE_QUERY_PARAMETERS,
                    true,
                )
            ) {
                $sanitized[$key] =
                    '[REDACTED]';

                continue;
            }

            /*
             * Pagination cursors.
             */
            if (
                $parentKey === 'cursors'
                && in_array(
                    $keyString,
                    self::PAGINATION_QUERY_PARAMETERS,
                    true,
                )
            ) {
                $sanitized[$key] =
                    '[REDACTED]';

                continue;
            }

            /*
             * Paging URLs.
             */
            if (
                $parentKey === 'paging'
                && in_array(
                    $keyString,
                    ['next', 'previous'],
                    true,
                )
                && is_string($item)
            ) {
                $sanitized[$key] =
                    $this->sanitizeUrlForLog(
                        $item,
                    );

                continue;
            }

            $sanitized[$key] =
                $this->sanitizeMetaValueForLog(
                    $item,
                    $keyString,
                );
        }

        return $sanitized;
    }


    /**
     * Sanitize URL for logging.
     *
     * Removes:
     *
     * - access_token
     * - appsecret_proof
     * - after
     * - before
     */
    private function sanitizeUrlForLog(
        string $url,
    ): string {
        $parsed =
            parse_url($url);

        if ($parsed === false) {
            return '[invalid-url]';
        }

        $scheme =
            $parsed['scheme'] ?? null;

        $host =
            $parsed['host'] ?? null;

        if (
            !is_string($scheme)
            || !is_string($host)
        ) {
            return '[invalid-url]';
        }

        $sanitized =
            "{$scheme}://{$host}";

        if (isset($parsed['port'])) {
            $port = $parsed['port'];

            if (
                is_int($port)
                || (
                    is_string($port)
                    && ctype_digit($port)
                )
            ) {
                $sanitized .= ':' . $port;
            }
        }

        $sanitized .=
            (string) (
                $parsed['path'] ?? ''
            );

        if (
            isset($parsed['query'])
            && is_string(
                $parsed['query'],
            )
        ) {
            $query = [];

            parse_str(
                $parsed['query'],
                $query,
            );

            foreach (
                array_merge(
                    self::SENSITIVE_QUERY_PARAMETERS,
                    self::PAGINATION_QUERY_PARAMETERS,
                )
                as $parameter
            ) {
                unset(
                    $query[$parameter],
                );
            }

            if ($query !== []) {
                $sanitized .=
                    '?'
                    . http_build_query(
                        $query,
                        '',
                        '&',
                        PHP_QUERY_RFC3986,
                    );
            }
        }

        return $sanitized;
    }
}