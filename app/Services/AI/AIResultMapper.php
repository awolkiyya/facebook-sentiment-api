<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AIResultMapper
{
    /**
     * Map the complete AI batch response into a Laravel-friendly structure.
     *
     * Topic and priority are optional AI-generated classifications.
     * Their labels are not restricted to a predefined vocabulary.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function mapBatch(array $response): array
    {
        $results = $response['results'] ?? null;

        Log::debug('AIResultMapper | Incoming batch response.', [
            'has_results' => array_key_exists('results', $response),
            'response_keys' => array_keys($response),
            'results_type' => get_debug_type($results),
            'result_count' => is_array($results)
                ? count($results)
                : null,
            'response' => $this->shouldLogPayload()
                ? $response
                : null,
        ]);

        if (!is_array($results)) {
            Log::error('AIResultMapper | Invalid batch response.', [
                'response_keys' => array_keys($response),
                'results_type' => get_debug_type($results),
            ]);

            throw new InvalidArgumentException(
                'AI response is missing a valid results array.'
            );
        }

        $mapped = [];

        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                Log::error(
                    'AIResultMapper | Invalid result structure.',
                    [
                        'index' => $index,
                        'result_type' => get_debug_type($result),
                        'result' => $this->shouldLogPayload()
                            ? $result
                            : null,
                    ]
                );

                throw new InvalidArgumentException(
                    sprintf(
                        'AI result at index %s must be an array.',
                        (string) $index,
                    )
                );
            }

            Log::debug(
                'AIResultMapper | Processing incoming result.',
                [
                    'index' => $index,
                    'external_id' => $result['id'] ?? null,
                    'available_fields' => array_keys($result),
                    'language_type' => get_debug_type(
                        $result['language'] ?? null
                    ),
                    'sentiment_type' => get_debug_type(
                        $result['sentiment'] ?? null
                    ),
                    'topic_type' => get_debug_type(
                        $result['topic'] ?? null
                    ),
                    'topic' => $this->shouldLogPayload()
                        ? ($result['topic'] ?? null)
                        : null,
                    'priority_type' => get_debug_type(
                        $result['priority'] ?? null
                    ),
                    'priority' => $this->shouldLogPayload()
                        ? ($result['priority'] ?? null)
                        : null,
                ]
            );

            try {
                $mapped[] = $this->mapResult(
                    $result,
                    (int) $index,
                );
            } catch (InvalidArgumentException $exception) {
                Log::error(
                    'AIResultMapper | Failed to map AI result.',
                    [
                        'index' => $index,
                        'external_id' => $result['id'] ?? null,
                        'available_fields' => array_keys($result),
                        'topic_type' => get_debug_type(
                            $result['topic'] ?? null
                        ),
                        'priority_type' => get_debug_type(
                            $result['priority'] ?? null
                        ),
                        'result' => $this->shouldLogPayload()
                            ? $result
                            : null,
                        'exception_message' => $exception->getMessage(),
                    ]
                );

                throw $exception;
            } catch (Throwable $exception) {
                Log::error(
                    'AIResultMapper | Unexpected mapping error.',
                    [
                        'index' => $index,
                        'external_id' => $result['id'] ?? null,
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ]
                );

                throw $exception;
            }
        }

        Log::debug('AIResultMapper | Batch mapping completed.', [
            'received_count' => count($results),
            'mapped_count' => count($mapped),
        ]);

        return $mapped;
    }

    /**
     * Map a single AI result.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function mapResult(
        array $result,
        int $index,
    ): array {
        $id = $this->requiredString(
            $result,
            'id',
            "AI result at index {$index}",
        );

        $language = $this->requiredArray(
            $result,
            'language',
            "AI result '{$id}'",
        );

        $sentiment = $this->requiredArray(
            $result,
            'sentiment',
            "AI result '{$id}'",
        );

        return [
            'external_id' => $id,

            /*
             * Language
             */
            'language_code' => $this->requiredString(
                $language,
                'code',
                "AI language result for '{$id}'",
            ),

            'language_confidence' => $this->confidence(
                $language,
                'confidence',
                "AI language result for '{$id}'",
            ),

            /*
             * Sentiment
             */
            'sentiment_label' => $this->requiredString(
                $sentiment,
                'label',
                "AI sentiment result for '{$id}'",
            ),

            'sentiment_label_id' => $this->optionalInteger(
                $sentiment,
                'label_id',
            ),

            'sentiment_confidence' => $this->confidence(
                $sentiment,
                'confidence',
                "AI sentiment result for '{$id}'",
            ),

            /*
             * Topic
             *
             * Accepts a string or an object containing "label".
             * The topic vocabulary is not restricted.
             * Missing or malformed optional confidence becomes null.
             */
            'topic_label' => $this->optionalLabel(
                $result['topic'] ?? null,
            ),

            'topic_confidence' => $this->optionalConfidence(
                $result['topic'] ?? null,
            ),

            /*
             * Priority
             *
             * Accepts a string or an object containing "label".
             * The priority vocabulary is not restricted.
             */
            'priority_label' => $this->optionalLabel(
                $result['priority'] ?? null,
            ),

            'priority_confidence' => $this->optionalConfidence(
                $result['priority'] ?? null,
            ),
        ];
    }

    /**
     * Extract a required non-empty string.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    private function requiredString(
        array $data,
        string $key,
        string $context,
    ): string {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "{$context} is missing required field '{$key}'."
            );
        }

        $value = $data[$key];

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "{$context} field '{$key}' must be a non-empty string."
            );
        }

        return trim($value);
    }

    /**
     * Extract a required object represented as an associative array.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function requiredArray(
        array $data,
        string $key,
        string $context,
    ): array {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "{$context} is missing required field '{$key}'."
            );
        }

        $value = $data[$key];

        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "{$context} field '{$key}' must be an object."
            );
        }

        return $value;
    }

    /**
     * Extract an optional classification label.
     *
     * Supported examples:
     * - "roads_infrastructure"
     * - ["label" => "roads_infrastructure", "confidence" => 0.95]
     * - null
     *
     * @return string|null
     */
    private function optionalLabel(mixed $value): ?string
    {
        if (is_string($value)) {
            $label = trim($value);

            return $label !== '' ? $label : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $label = $value['label'] ?? null;

        if (!is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label !== '' ? $label : null;
    }

    /**
     * Extract optional confidence from a classification.
     *
     * Invalid or missing optional confidence values become null.
     */
    private function optionalConfidence(mixed $value): ?float
    {
        if (!is_array($value)) {
            return null;
        }

        if (!array_key_exists('confidence', $value)) {
            return null;
        }

        $confidence = $value['confidence'];

        if (
            !is_numeric($confidence)
            || !is_finite((float) $confidence)
        ) {
            return null;
        }

        $confidence = (float) $confidence;

        if ($confidence < 0.0 || $confidence > 1.0) {
            return null;
        }

        return $confidence;
    }

    /**
     * Extract and validate required confidence.
     *
     * Confidence must be numeric and between 0 and 1.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    private function confidence(
        array $data,
        string $key,
        string $context,
    ): float {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "{$context} is missing required field '{$key}'."
            );
        }

        $value = $data[$key];

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                "{$context} field '{$key}' must be numeric."
            );
        }

        $confidence = (float) $value;

        if (
            !is_finite($confidence)
            || $confidence < 0.0
            || $confidence > 1.0
        ) {
            throw new InvalidArgumentException(
                "{$context} field '{$key}' must be between 0 and 1."
            );
        }

        return $confidence;
    }

    /**
     * Extract an optional integer.
     *
     * @param array<string, mixed> $data
     */
    private function optionalInteger(
        array $data,
        string $key,
    ): ?int {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
        ) {
            return (int) $value;
        }

        if (
            is_float($value)
            && is_finite($value)
            && floor($value) === $value
        ) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "AI field '{$key}' must be an integer."
        );
    }

    /**
     * Determine whether full incoming payloads should be logged.
     *
     * Keep AI_LOG_PAYLOADS disabled in production unless temporarily
     * needed for debugging.
     */
    private function shouldLogPayload(): bool
    {
        return (bool) config('services.ai.log_payloads', false);
    }
}