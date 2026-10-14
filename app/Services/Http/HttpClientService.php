<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HttpClientService
{
    /**
     * Default HTTP timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 15;

    /**
     * Default connection timeout in seconds.
     */
    private const DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * Default number of retry attempts.
     *
     * Laravel's retry() attempts parameter represents the total number
     * of attempts, not additional retries.
     */
    private const DEFAULT_RETRIES = 2;

    /**
     * Default retry delay in milliseconds.
     */
    private const DEFAULT_RETRY_DELAY = 500;

    /**
     * Query parameters that must never be written to logs.
     */
    private const SENSITIVE_QUERY_PARAMETERS = [
        'access_token',
        'appsecret_proof',
        'after',
        'before',
        'api_key',
        'key',
        'token',
        'secret',
    ];

    /**
     * HTTP headers that must never be written to logs.
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'x-auth-token',
        'x-access-token',
        'x-internal-api-key',
        'cookie',
        'set-cookie',
    ];

    /**
     * Perform a GET request.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(
        string $url,
        array $query = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): Response {
        return $this->request(
            method: 'get',
            url: $url,
            query: $query,
            headers: $headers,
            timeout: $timeout,
        );
    }

    /**
     * Perform a POST request.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): Response {
        return $this->request(
            method: 'post',
            url: $url,
            data: $data,
            headers: $headers,
            timeout: $timeout,
        );
    }

    /**
     * Perform a PUT request.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function put(
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): Response {
        return $this->request(
            method: 'put',
            url: $url,
            data: $data,
            headers: $headers,
            timeout: $timeout,
        );
    }

    /**
     * Perform a PATCH request.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function patch(
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): Response {
        return $this->request(
            method: 'patch',
            url: $url,
            data: $data,
            headers: $headers,
            timeout: $timeout,
        );
    }

    /**
     * Perform a DELETE request.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function delete(
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
    ): Response {
        return $this->request(
            method: 'delete',
            url: $url,
            data: $data,
            headers: $headers,
            timeout: $timeout,
        );
    }

    /**
     * Perform a generic HTTP request.
     *
     * URL query parameters are extracted and merged with the supplied
     * query parameters. The final query is sent exactly once through
     * Guzzle's query option.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $url,
        array $query = [],
        array $data = [],
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $retries = self::DEFAULT_RETRIES,
        int $retryDelay = self::DEFAULT_RETRY_DELAY,
    ): Response {
        $this->validateConfiguration(
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            retries: $retries,
            retryDelay: $retryDelay,
        );

        $method = strtoupper(trim($method));

        if ($method === '') {
            throw new RuntimeException(
                'HTTP method cannot be empty.'
            );
        }

        /*
         * Preserve query parameters already present in the URL.
         *
         * Example:
         *
         * URL:
         * /posts?limit=25&after=CURSOR
         *
         * Additional query:
         * access_token=TOKEN
         *
         * Final request:
         * /posts?limit=25&after=CURSOR&access_token=TOKEN
         */
        $urlQuery = $this->parseQueryFromUrl($url);

        /*
         * Explicitly supplied query parameters take precedence over
         * parameters already present in the URL.
         */
        $mergedQuery = array_merge(
            $urlQuery,
            $query,
        );

        /*
         * Remove the query string from the URL.
         *
         * The complete query is supplied through the HTTP client's
         * query option exactly once.
         */
        $baseUrl = $this->removeQueryFromUrl($url);

        /*
         * Generate a local request ID so the request lifecycle can be
         * traced across start, retry, success and failure logs.
         *
         * This is intentionally independent from the application-level
         * request_id that may already exist in the calling service.
         */
        $httpRequestId = (string) \Illuminate\Support\Str::uuid();

        $this->logRequestStarted(
            httpRequestId: $httpRequestId,
            method: $method,
            url: $baseUrl,
            query: $mergedQuery,
            headers: $headers,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            retries: $retries,
        );

        $client = Http::acceptJson()
            ->withHeaders($headers)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->retry(
                $retries,
                $retryDelay,
                function (
                    Throwable $exception,
                    $request
                ) use ($httpRequestId, $method, $baseUrl): bool {
                    $shouldRetry = $this->shouldRetry($exception);

                    if ($shouldRetry) {
                        Log::warning(
                            'HTTP CLIENT | TRANSIENT FAILURE - RETRYING',
                            [
                                'http_request_id' => $httpRequestId,
                                'method' => $method,
                                'url' => $baseUrl,
                                'exception_class' => $exception::class,
                                'exception_message' => $exception->getMessage(),
                            ]
                        );
                    }

                    return $shouldRetry;
                }
            );

        $options = [
            'query' => $mergedQuery,
        ];

        /*
         * Only send a JSON body when:
         *
         * - actual data exists
         * - method supports a request body
         */
        if (
            !empty($data)
            && !in_array($method, ['GET', 'HEAD'], true)
        ) {
            $options['json'] = $data;
        }

        $startedAt = microtime(true);

        try {
            $response = $client->send(
                method: $method,
                url: $baseUrl,
                options: $options,
            );

            $durationMs = (int) round(
                (microtime(true) - $startedAt) * 1000
            );

            $this->logResponse(
                httpRequestId: $httpRequestId,
                method: $method,
                url: $baseUrl,
                response: $response,
                durationMs: $durationMs,
            );

            /*
             * Preserve the existing behavior:
             *
             * - successful 2xx responses are returned
             * - 4xx / 5xx responses throw Laravel's RequestException
             */
            return $response->throw();
        } catch (Throwable $exception) {
            $durationMs = (int) round(
                (microtime(true) - $startedAt) * 1000
            );

            $this->logRequestFailure(
                httpRequestId: $httpRequestId,
                method: $method,
                url: $baseUrl,
                exception: $exception,
                durationMs: $durationMs,
            );

            throw $exception;
        }
    }

    /**
     * Validate HTTP client configuration.
     */
    private function validateConfiguration(
        int $timeout,
        int $connectTimeout,
        int $retries,
        int $retryDelay,
    ): void {
        if ($timeout <= 0) {
            throw new RuntimeException(
                'HTTP timeout must be greater than zero.'
            );
        }

        if ($connectTimeout <= 0) {
            throw new RuntimeException(
                'HTTP connection timeout must be greater than zero.'
            );
        }

        if ($retries < 1) {
            throw new RuntimeException(
                'HTTP retry attempts must be at least 1.'
            );
        }

        if ($retryDelay < 0) {
            throw new RuntimeException(
                'HTTP retry delay cannot be negative.'
            );
        }
    }

    /**
     * Extract query parameters from a URL.
     *
     * @return array<string, mixed>
     */
    private function parseQueryFromUrl(string $url): array
    {
        $queryString = parse_url(
            $url,
            PHP_URL_QUERY
        );

        if (
            !is_string($queryString)
            || $queryString === ''
        ) {
            return [];
        }

        $query = [];

        parse_str(
            $queryString,
            $query
        );

        return $query;
    }

    /**
     * Remove the query string from a URL.
     */
    private function removeQueryFromUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $baseUrl = '';

        if (isset($parts['scheme'])) {
            $baseUrl .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $baseUrl .= $parts['user'];

            if (isset($parts['pass'])) {
                $baseUrl .= ':' . $parts['pass'];
            }

            $baseUrl .= '@';
        }

        if (isset($parts['host'])) {
            $baseUrl .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $baseUrl .= ':' . $parts['port'];
        }

        $baseUrl .= $parts['path'] ?? '';

        return $baseUrl;
    }

    /**
     * Log the beginning of an HTTP request.
     *
     * Never logs:
     *
     * - API keys
     * - Authorization headers
     * - cookies
     * - access tokens
     * - request body
     * - cursor values
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function logRequestStarted(
        string $httpRequestId,
        string $method,
        string $url,
        array $query,
        array $headers,
        int $timeout,
        int $connectTimeout,
        int $retries,
    ): void {
        if (!$this->shouldLogHttpRequests()) {
            return;
        }

        Log::debug(
            'HTTP CLIENT | REQUEST STARTED',
            [
                'http_request_id' => $httpRequestId,
                'method' => $method,
                'url' => $url,

                /*
                 * Only sanitized query values are logged.
                 */
                'query' => $this->sanitizeQueryForLogging($query),

                /*
                 * Header names are useful for diagnosing authentication,
                 * but their values must never be logged.
                 */
                'header_keys' => array_keys($headers),

                /*
                 * This is particularly useful for diagnosing the
                 * Laravel -> FastAPI authentication problem.
                 */
                'authentication' => [
                    'authorization_header_present' =>
                        $this->hasHeader(
                            $headers,
                            'Authorization'
                        ),

                    'x_api_key_present' =>
                        $this->hasHeader(
                            $headers,
                            'X-API-Key'
                        ),

                    'x_auth_token_present' =>
                        $this->hasHeader(
                            $headers,
                            'X-Auth-Token'
                        ),
                ],

                'timeout_seconds' => $timeout,
                'connect_timeout_seconds' => $connectTimeout,
                'configured_attempts' => $retries,
            ]
        );
    }

    /**
     * Log a completed HTTP response.
     */
    private function logResponse(
        string $httpRequestId,
        string $method,
        string $url,
        Response $response,
        int $durationMs,
    ): void {
        if (!$this->shouldLogHttpRequests()) {
            return;
        }

        $status = $response->status();

        $context = [
            'http_request_id' => $httpRequestId,
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'successful' => $response->successful(),
            'client_error' => $response->clientError(),
            'server_error' => $response->serverError(),
            'duration_ms' => $durationMs,
        ];

        /*
         * Do not log response bodies by default.
         *
         * AI responses may contain user-generated Facebook content,
         * model output or other sensitive information.
         */
        if ($response->successful()) {
            Log::debug(
                'HTTP CLIENT | REQUEST COMPLETED',
                $context
            );

            return;
        }

        /*
         * 401/403 are especially useful for authentication debugging,
         * so record the status explicitly without exposing the response
         * body or credentials.
         */
        if (
            $response->status() === 401
            || $response->status() === 403
        ) {
            Log::warning(
                'HTTP CLIENT | AUTHENTICATION FAILED',
                $context
            );

            return;
        }

        Log::warning(
            'HTTP CLIENT | REQUEST RETURNED ERROR',
            $context
        );
    }

    /**
     * Log an HTTP request failure.
     */
    private function logRequestFailure(
        string $httpRequestId,
        string $method,
        string $url,
        Throwable $exception,
        int $durationMs,
    ): void {
        if (!$this->shouldLogHttpRequests()) {
            return;
        }

        Log::error(
            'HTTP CLIENT | REQUEST FAILED',
            [
                'http_request_id' => $httpRequestId,
                'method' => $method,
                'url' => $url,
                'duration_ms' => $durationMs,
                'exception_class' => $exception::class,
                'exception_message' => $this->sanitizeExceptionMessage(
                    $exception->getMessage()
                ),
            ]
        );
    }

    /**
     * Sanitize query parameters before logging.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function sanitizeQueryForLogging(
        array $query
    ): array {
        $sanitized = $query;

        foreach (
            self::SENSITIVE_QUERY_PARAMETERS
            as $parameter
        ) {
            if (array_key_exists($parameter, $sanitized)) {
                $sanitized[$parameter] = '[REDACTED]';
            }
        }

        return $sanitized;
    }

    /**
     * Determine whether a header exists without considering case.
     *
     * HTTP header names are case-insensitive.
     *
     * @param array<string, string> $headers
     */
    private function hasHeader(
        array $headers,
        string $headerName,
    ): bool {
        foreach (array_keys($headers) as $existingHeader) {
            if (
                strcasecmp(
                    $existingHeader,
                    $headerName
                ) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether HTTP request logging is enabled.
     *
     * This is intentionally independent from Facebook-specific logging.
     */
    private function shouldLogHttpRequests(): bool
    {
        return (bool) config(
            'services.http.log_requests',
            config(
                'app.debug',
                false
            )
        );
    }

    /**
     * Remove potentially sensitive information from exception messages.
     *
     * The HTTP client normally does not include request headers in
     * Laravel's exception message, but this provides an additional
     * defensive layer for known secret patterns.
     */
    private function sanitizeExceptionMessage(
        string $message
    ): string {
        if ($message === '') {
            return $message;
        }

        /*
         * Do not attempt to expose arbitrary exception payloads.
         * Keep the message useful while replacing common credential
         * patterns.
         */
        $patterns = [
            '/(Bearer\s+)[^\s]+/i',
            '/(X-API-Key\s*[:=]\s*)[^\s,]+/i',
            '/(api[_-]?key\s*[:=]\s*)[^\s,]+/i',
            '/(access[_-]?token\s*[:=]\s*)[^\s,]+/i',
        ];

        $replacements = [
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1[REDACTED]',
        ];

        return preg_replace(
            $patterns,
            $replacements,
            $message
        ) ?? '[SANITIZED HTTP CLIENT EXCEPTION]';
    }

    /**
     * Determine whether a failed request should be retried.
     *
     * Only connection-level transient failures are retried.
     *
     * HTTP 401, 403, 400, 404 and other application-level responses
     * are NOT retried here.
     */
    private function shouldRetry(
        Throwable $exception
    ): bool {
        return $exception instanceof ConnectionException;
    }
}
