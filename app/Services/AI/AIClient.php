<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\SystemSetting;
use App\Services\Http\HttpClientService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AIClient
{
    private const MAX_BATCH_SIZE = 500;

    private const DEFAULT_BATCH_SIZE = 32;

    private const MAX_TEXT_LENGTH = 10_000;

    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {
    }

    /**
     * Analyze multiple texts using the Python AI service.
     *
     * @param array<int, array{id: string, text: string}> $items
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    public function analyzeBatch(array $items): array
    {
        $this->validateBatchItems($items);

        $endpoint = $this->buildEndpoint(
            '/api/v1/analyze/batch'
        );

        $apiKey = $this->getApiKey();

        $timeout = $this->getTimeout();

        try {
            $response = $this->httpClient->post(
                url: $endpoint,
                data: [
                    'items' => $items,
                ],
                headers: [
                    'X-Internal-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                timeout: $timeout,
            );

            /*
             * Log the AI response before validation.
             *
             * Do not log the request payload because it contains
             * Facebook comment text.
             */
            Log::debug('AI service batch response received.', [
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'response_body' => $this->getSafeResponseBody(
                    $response,
                ),
            ]);

            return $this->validateResponse($response);

        } catch (ConnectionException $exception) {
            Log::error('Unable to connect to the AI service.', [
                'endpoint' => $endpoint,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new AIException(
                message: 'Unable to connect to the AI service.',
                code: 0,
                previous: $exception,
            );

        } catch (RequestException $exception) {
            Log::error('AI service returned an HTTP error.', [
                'endpoint' => $endpoint,
                'http_status' => $exception->response?->status(),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new AIException(
                message: $this->getRequestErrorMessage($exception),
                code: $exception->response?->status() ?? 0,
                previous: $exception,
            );

        } catch (AIException $exception) {
            Log::warning('AI service response validation failed.', [
                'endpoint' => $endpoint,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;

        } catch (Throwable $exception) {
            Log::error('Unexpected AI service request failure.', [
                'endpoint' => $endpoint,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw new AIException(
                message: 'AI service request failed.',
                code: 0,
                previous: $exception,
            );
        }
    }

    /**
     * Return a single-text analysis using the batch endpoint.
     *
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    public function analyze(
        string $id,
        string $text,
    ): array {
        return $this->analyzeBatch([
            [
                'id' => $id,
                'text' => $text,
            ],
        ]);
    }

    /**
     * Get the configured AI batch size.
     *
     * @throws AIException
     */
    public function getBatchSize(): int
    {
        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            throw new AIException(
                'System settings could not be loaded.'
            );
        }

        $batchSize = $settings->ai_batch_size;

        if (
            !is_int($batchSize)
            && !is_numeric($batchSize)
        ) {
            return self::DEFAULT_BATCH_SIZE;
        }

        $batchSize = (int) $batchSize;

        if ($batchSize <= 0) {
            return self::DEFAULT_BATCH_SIZE;
        }

        return min(
            $batchSize,
            self::MAX_BATCH_SIZE,
        );
    }

    /**
     * Build the complete AI endpoint URL.
     *
     * @throws AIException
     */
    private function buildEndpoint(string $path): string
    {
        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            throw new AIException(
                'System settings could not be loaded.'
            );
        }

        $baseUrl = $settings->ai_service_url;

        if (
            !is_string($baseUrl)
            || trim($baseUrl) === ''
        ) {
            throw new AIException(
                'AI service URL is not configured.'
            );
        }

        $baseUrl = rtrim(trim($baseUrl), '/');

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new AIException(
                'AI service URL is invalid.'
            );
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Get and validate the AI API key.
     *
     * @throws AIException
     */
    private function getApiKey(): string
    {
        $apiKey = config('services.ai.key');

        if (
            !is_string($apiKey)
            || trim($apiKey) === ''
        ) {
            throw new AIException(
                'AI service API key is not configured.'
            );
        }

        $apiKey = trim($apiKey);

        if (strlen($apiKey) < 32) {
            throw new AIException(
                'AI service API key must contain at least 32 characters.'
            );
        }

        return $apiKey;
    }

    /**
     * Get and validate the request timeout.
     *
     * @throws AIException
     */
    private function getTimeout(): int
    {
        $timeout = config(
            'services.ai.timeout',
            self::DEFAULT_TIMEOUT,
        );

        if (
            !is_int($timeout)
            && !is_numeric($timeout)
        ) {
            throw new AIException(
                'AI service timeout configuration is invalid.'
            );
        }

        $timeout = (int) $timeout;

        if ($timeout <= 0) {
            throw new AIException(
                'AI service timeout must be greater than zero.'
            );
        }

        return $timeout;
    }

    /**
     * Validate batch input before sending it to Python.
     *
     * @param array<int, array{id: string, text: string}> $items
     *
     * @throws InvalidArgumentException
     * @throws AIException
     */
    private function validateBatchItems(array $items): void
    {
        if ($items === []) {
            throw new InvalidArgumentException(
                'AI batch must contain at least one item.'
            );
        }

        $batchSize = $this->getBatchSize();

        if (count($items) > $batchSize) {
            throw new InvalidArgumentException(
                sprintf(
                    'AI batch cannot contain more than %d items.',
                    $batchSize,
                )
            );
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'AI batch item at index %d must be an array.',
                        $index,
                    )
                );
            }

            if (
                !isset($item['id'])
                || !is_string($item['id'])
                || trim($item['id']) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'AI batch item at index %d must have a valid id.',
                        $index,
                    )
                );
            }

            if (
                !isset($item['text'])
                || !is_string($item['text'])
                || trim($item['text']) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'AI batch item at index %d must have valid text.',
                        $index,
                    )
                );
            }

            if (
                mb_strlen($item['text'])
                > self::MAX_TEXT_LENGTH
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'AI batch item at index %d exceeds the maximum text length of %d characters.',
                        $index,
                        self::MAX_TEXT_LENGTH,
                    )
                );
            }
        }
    }

    /**
     * Log-safe response representation.
     *
     * The response is decoded as JSON when possible. For non-JSON
     * responses, only the HTTP status and response type are logged.
     *
     * @return array<string, mixed>
     */
    private function getSafeResponseBody(
        Response $response,
    ): array {
        $payload = $response->json();

        if (!is_array($payload)) {
            return [
                'format' => 'non_json',
                'body_length' => strlen($response->body()),
            ];
        }

        /*
         * The AI result contains predictions and metadata rather than
         * the original comment text. Log only these returned fields.
         */
        $results = [];

        foreach (($payload['results'] ?? []) as $index => $result) {
            if (!is_array($result)) {
                $results[] = [
                    'index' => $index,
                    'result_type' => get_debug_type($result),
                ];

                continue;
            }

            $results[] = [
                'id' => $result['id'] ?? null,
                'language' => $result['language'] ?? null,
                'sentiment' => $result['sentiment'] ?? null,
                'topic' => $result['topic'] ?? null,
                'priority' => $result['priority'] ?? null,
            ];
        }

        return [
            'results' => $results,
            'model' => $payload['model'] ?? null,
            'additional_fields' => array_diff(
                array_keys($payload),
                ['results', 'model'],
            ),
        ];
    }

    /**
     * Validate the Python AI response.
     *
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    private function validateResponse(
        Response $response,
    ): array {
        $payload = $response->json();

        if (!is_array($payload)) {
            throw new AIException(
                'AI service returned an invalid JSON response.'
            );
        }

        if (
            !array_key_exists('results', $payload)
            || !is_array($payload['results'])
        ) {
            throw new AIException(
                'AI service response is missing the results array.'
            );
        }

        if (
            !array_key_exists('model', $payload)
            || !is_array($payload['model'])
        ) {
            throw new AIException(
                'AI service response is missing model information.'
            );
        }

        foreach ($payload['results'] as $index => $result) {
            $this->validateResult($result, $index);
        }

        return $payload;
    }

    /**
     * Validate one AI result.
     *
     * @throws AIException
     */
    private function validateResult(
        mixed $result,
        int|string $index,
    ): void {
        if (!is_array($result)) {
            throw new AIException(
                sprintf(
                    'AI service returned an invalid result at index %s.',
                    (string) $index,
                )
            );
        }

        if (
            !array_key_exists('id', $result)
            || !is_string($result['id'])
            || trim($result['id']) === ''
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid id.',
                    (string) $index,
                )
            );
        }

        $this->validateLanguage($result, $index);

        $this->validateSentiment($result, $index);

        /*
         * Topic and priority are produced by the AI service.
         * Their labels are not restricted to a predefined list.
         * Basic structure validation is retained when they exist.
         */
        if (array_key_exists('topic', $result)) {
            $this->validateAnalysisPart(
                $result['topic'],
                'topic',
                $index,
            );
        }

        if (array_key_exists('priority', $result)) {
            $this->validateAnalysisPart(
                $result['priority'],
                'priority',
                $index,
            );
        }
    }

    /**
     * Validate language result.
     *
     * @param array<string, mixed> $result
     *
     * @throws AIException
     */
    private function validateLanguage(
        array $result,
        int|string $index,
    ): void {
        if (
            !array_key_exists('language', $result)
            || !is_array($result['language'])
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s is missing language information.',
                    (string) $index,
                )
            );
        }

        $language = $result['language'];

        if (
            !array_key_exists('code', $language)
            || !is_string($language['code'])
            || trim($language['code']) === ''
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid language code.',
                    (string) $index,
                )
            );
        }

        $allowedLanguages = ['am', 'om', 'en'];

        if (!in_array($language['code'], $allowedLanguages, true)) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s returned unsupported language code "%s".',
                    (string) $index,
                    $language['code'],
                )
            );
        }

        $this->validateConfidence(
            $language,
            'language',
            $index,
        );
    }

    /**
     * Validate sentiment result.
     *
     * @param array<string, mixed> $result
     *
     * @throws AIException
     */
    private function validateSentiment(
        array $result,
        int|string $index,
    ): void {
        if (
            !array_key_exists('sentiment', $result)
            || !is_array($result['sentiment'])
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s is missing sentiment information.',
                    (string) $index,
                )
            );
        }

        $sentiment = $result['sentiment'];

        if (
            !array_key_exists('label', $sentiment)
            || !is_string($sentiment['label'])
            || trim($sentiment['label']) === ''
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid sentiment label.',
                    (string) $index,
                )
            );
        }

        $allowedSentiments = [
            'negative',
            'neutral',
            'positive',
        ];

        if (!in_array($sentiment['label'], $allowedSentiments, true)) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s returned unsupported sentiment "%s".',
                    (string) $index,
                    $sentiment['label'],
                )
            );
        }

        if (
            !array_key_exists('label_id', $sentiment)
            || !is_numeric($sentiment['label_id'])
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid sentiment label_id.',
                    (string) $index,
                )
            );
        }

        $labelId = (int) $sentiment['label_id'];

        if ($labelId < 0 || $labelId > 2) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid sentiment label_id.',
                    (string) $index,
                )
            );
        }

        $this->validateConfidence(
            $sentiment,
            'sentiment',
            $index,
        );
    }

    /**
     * Validate topic or priority structure without restricting labels.
     *
     * @param mixed $analysis
     *
     * @throws AIException
     */
    private function validateAnalysisPart(
        mixed $analysis,
        string $part,
        int|string $index,
    ): void {
        if (!is_array($analysis)) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has invalid %s information.',
                    (string) $index,
                    $part,
                )
            );
        }

        if (
            !array_key_exists('label', $analysis)
            || !is_string($analysis['label'])
            || trim($analysis['label']) === ''
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid %s label.',
                    (string) $index,
                    $part,
                )
            );
        }

        $this->validateConfidence(
            $analysis,
            $part,
            $index,
        );
    }

    /**
     * Validate confidence.
     *
     * @param array<string, mixed> $analysis
     *
     * @throws AIException
     */
    private function validateConfidence(
        array $analysis,
        string $part,
        int|string $index,
    ): void {
        if (
            !array_key_exists('confidence', $analysis)
            || !is_numeric($analysis['confidence'])
        ) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an invalid %s confidence.',
                    (string) $index,
                    $part,
                )
            );
        }

        $confidence = (float) $analysis['confidence'];

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new AIException(
                sprintf(
                    'AI service result at index %s has an out-of-range %s confidence.',
                    (string) $index,
                    $part,
                )
            );
        }
    }

    /**
     * Get a useful error message for an HTTP failure.
     */
    private function getRequestErrorMessage(
        RequestException $exception,
    ): string {
        $status = $exception->response?->status();

        return match ($status) {
            401, 403 => 'AI service authentication failed.',
            408 => 'AI service request timed out.',
            429 => 'AI service rate limit was exceeded.',
            500, 502, 503, 504 => 'AI service is temporarily unavailable.',
            default => 'AI service returned an HTTP error.',
        };
    }
}