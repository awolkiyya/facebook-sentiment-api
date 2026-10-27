<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Services\Http\HttpClientService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class FacebookGraphApiService
{
    private const VERSION = 'v26.0';

    private const HOST = 'graph.facebook.com';

    private const PAGE_LIMIT = 25;

    private const TIMEOUT = 30;

    private const SENSITIVE_PARAMETERS = [
        'access_token',
        'appsecret_proof',
        'after',
        'before',
    ];

    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {}

    /**
     * Fetch one page of Meta posts.
     *
     * @return array{data: array<int, array<string, mixed>>, next: ?string}
     */
    public function fetchPosts(
        FacebookPage $page,
        string $url,
        string $accessToken,
        bool $firstPage,
    ): array {
        $this->assertSafeGraphUrl($url);

        $query = [
            'access_token' => $accessToken,
        ];

        if ($firstPage) {
            $query['limit'] = self::PAGE_LIMIT;
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
        }

        try {
            $response = $this->httpClient->get(
                url: $url,
                query: $query,
                timeout: self::TIMEOUT,
            );

            if (
                method_exists($response, 'failed')
                && $response->failed()
            ) {
                $message = $response->json('error.message');

                Log::warning('Facebook Graph API HTTP failure.', [
                    'page_internal_id' => $page->id,
                    'http_status' => $response->status(),
                    'meta_error_code' => $response->json('error.code'),
                    'meta_error_subcode' => $response->json('error.error_subcode'),
                ]);

                throw new RuntimeException(
                    is_string($message)
                        ? "Meta Graph API error: {$message}"
                        : 'Meta Graph API returned an HTTP error.'
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw new RuntimeException(
                    'Meta Graph API returned an invalid JSON response.'
                );
            }

            if (isset($payload['error'])) {
                Log::warning('Meta Graph API returned an error.', [
                    'page_internal_id' => $page->id,
                    'error_code' => data_get($payload, 'error.code'),
                    'error_subcode' => data_get($payload, 'error.error_subcode'),
                    'error_type' => data_get($payload, 'error.type'),
                ]);

                $message = data_get($payload, 'error.message');

                throw new RuntimeException(
                    is_string($message)
                        ? "Meta Graph API error: {$message}"
                        : 'Meta Graph API returned an error.'
                );
            }

            $posts = $payload['data'] ?? null;

            if (! is_array($posts)) {
                throw new RuntimeException(
                    'Meta posts response does not contain a valid data array.'
                );
            }

            $validPosts = [];

            foreach ($posts as $post) {
                if (
                    is_array($post)
                    && isset($post['id'])
                    && is_string($post['id'])
                    && trim($post['id']) !== ''
                ) {
                    $validPosts[] = $post;
                }
            }

            $next = $this->resolveNextUrl($payload);

            return [
                'data' => $validPosts,
                'next' => $next,
            ];
        } catch (RequestException $exception) {
            Log::error('Facebook Graph API request exception.', [
                'page_internal_id' => $page->id,
                'exception_class' => $exception::class,
                'http_status' => $exception->response?->status(),
            ]);

            throw new RuntimeException(
                'Facebook Graph API request failed.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            Log::error('Facebook Graph API request failed.', [
                'page_internal_id' => $page->id,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function buildPostsUrl(string $facebookPageId): string
    {
        return sprintf(
            'https://%s/%s/%s/posts',
            self::HOST,
            self::VERSION,
            rawurlencode($facebookPageId),
        );
    }

    /**
     * Validate a Meta URL before following it.
     */
    public function assertSafeGraphUrl(string $url): void
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! str_starts_with(
                (string) ($parts['path'] ?? ''),
                '/' . self::VERSION . '/',
            )
        ) {
            throw new RuntimeException(
                'Meta returned an invalid Facebook pagination URL.'
            );
        }
    }

    /**
     * Remove authentication material from the paging URL.
     *
     * Meta's after/before cursors remain intact in the request URL.
     */
    public function preparePaginationUrl(string $url): string
    {
        $this->assertSafeGraphUrl($url);

        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new RuntimeException('Invalid Meta pagination URL.');
        }

        $query = [];

        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        unset(
            $query['access_token'],
            $query['appsecret_proof'],
        );

        $prepared = 'https://' . self::HOST
            . (string) ($parts['path'] ?? '');

        if ($query !== []) {
            $prepared .= '?' . http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
        }

        return $prepared;
    }

    /**
     * Return a stable fingerprint without exposing cursors or tokens.
     */
    public function paginationFingerprint(string $url): string
    {
        $this->assertSafeGraphUrl($url);

        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new RuntimeException('Invalid Meta pagination URL.');
        }

        $query = [];

        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        unset(
            $query['access_token'],
            $query['appsecret_proof'],
        );

        ksort($query);

        return hash(
            'sha256',
            strtolower((string) ($parts['host'] ?? ''))
                . (string) ($parts['path'] ?? '')
                . '?'
                . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Never write raw paging URLs or cursors to logs.
     */
    public function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
        ) {
            return '[invalid-url]';
        }

        return $parts['scheme'] . '://'
            . $parts['host']
            . (string) ($parts['path'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveNextUrl(array $payload): ?string
    {
        $next = data_get($payload, 'paging.next');

        if (! is_string($next) || trim($next) === '') {
            return null;
        }

        $next = trim($next);

        $this->assertSafeGraphUrl($next);

        return $this->preparePaginationUrl($next);
    }
}
