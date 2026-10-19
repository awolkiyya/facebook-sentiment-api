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
     * Topic and priority labels are optional and are not restricted to
     * a predefined vocabulary.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function mapBatch(array $response): array
    {
        $results = $response['results'] ?? null;

        $logPayload = $this->shouldLogPayload();

        Log::debug('AIResultMapper | Incoming batch response.', [
            'has_results' => array_key_exists('results', $response),
            'response_keys' => array_keys($response),
            'results_type' => get_debug_type($results),
            'result_count' => is_array($results)
                ? count($results)
                : null,
            'response' => $logPayload ? $response : null,
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
                        'result' => $logPayload ? $result : null,
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

                    'priority_type' => get_debug_type(
                        $result['priority'] ?? null
                    ),

                    'sentiment_scores_type' => get_debug_type(
                        is_array($result['sentiment'] ?? null)
                            ? ($result['sentiment']['scores'] ?? null)
                            : null
                    ),

                    'processing_time_ms' =>
                        $result['processing_time_ms'] ?? null,

                    'topic' => $logPayload
                        ? ($result['topic'] ?? null)
                        : null,

                    'priority' => $logPayload
                        ? ($result['priority'] ?? null)
                        : null,
                ]
            );

            try {
                $mappedResult = $this->mapResult(
                    $result,
                    (int) $index,
                );

                $mapped[] = $mappedResult;

                Log::debug(
                    'AIResultMapper | Result mapped successfully.',
                    [
                        'index' => $index,
                        'external_id' => $mappedResult['external_id'],

                        'language_code' =>
                            $mappedResult['language_code'],

                        'sentiment_label' =>
                            $mappedResult['sentiment_label'],

                        'has_sentiment_scores' =>
                            $mappedResult['positive_score'] !== null
                            && $mappedResult['neutral_score'] !== null
                            && $mappedResult['negative_score'] !== null,

                        'topic_label' => $mappedResult['topic_label'],

                        'topic_confidence' =>
                            $mappedResult['topic_confidence'],

                        'priority_label' =>
                            $mappedResult['priority_label'],

                        'priority_confidence' =>
                            $mappedResult['priority_confidence'],

                        'processing_time_ms' =>
                            $mappedResult['processing_time_ms'],
                    ]
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

                        'result' => $logPayload ? $result : null,

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
     * Expected classification structure:
     *
     * [
     *     'topic' => [
     *         'label' => 'roads_infrastructure',
     *         'confidence' => 0.95,
     *     ],
     *     'priority' => [
     *         'label' => 'high',
     *         'confidence' => 0.88,
     *     ],
     * ]
     *
     * Topic and priority can also be plain strings.
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

        $scores = is_array($sentiment['scores'] ?? null)
            ? $sentiment['scores']
            : [];

        return [
            /*
             * External identity
             */
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
             * Sentiment classification
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
             * Sentiment probability scores
             *
             * Missing or invalid optional scores become null.
             */
            'positive_score' => $this->optionalScore(
                $scores,
                'positive',
            ),

            'neutral_score' => $this->optionalScore(
                $scores,
                'neutral',
            ),

            'negative_score' => $this->optionalScore(
                $scores,
                'negative',
            ),

            /*
             * Topic classification
             *
             * Supports a string or an object containing label and
             * confidence. Labels are not restricted to fixed values.
             */
            'topic_label' => $this->optionalLabel(
                $result['topic'] ?? null,
            ),

            'topic_confidence' => $this->optionalConfidence(
                $result['topic'] ?? null,
            ),

            /*
             * Priority classification
             */
            'priority_label' => $this->optionalLabel(
                $result['priority'] ?? null,
            ),

            'priority_confidence' => $this->optionalConfidence(
                $result['priority'] ?? null,
            ),

            /*
             * Processing metadata
             *
             * The AI service must return this field for it to be mapped.
             */
            'processing_time_ms' => $this->optionalInteger(
                $result,
                'processing_time_ms',
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
     * Supported values:
     * - "roads_infrastructure"
     * - ["label" => "roads_infrastructure", "confidence" => 0.95]
     * - null
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
     * Extract an optional probability score.
     *
     * Valid values must be numeric and between 0 and 1.
     *
     * @param array<string, mixed> $scores
     */
    private function optionalScore(
        array $scores,
        string $key,
    ): ?float {
        if (!array_key_exists($key, $scores)) {
            return null;
        }

        $value = $scores[$key];

        if (
            !is_numeric($value)
            || !is_finite((float) $value)
        ) {
            return null;
        }

        $score = (float) $value;

        if ($score < 0.0 || $score > 1.0) {
            return null;
        }

        return $score;
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
     * Missing values become null. Present but invalid values throw an
     * exception so malformed processing metadata is not silently accepted.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    private function optionalInteger(
        array $data,
        string $key,
    ): ?int {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
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
     * needed for debugging. AI payloads may contain user-generated content.
     */
    private function shouldLogPayload(): bool
    {
        return (bool) config('services.ai.log_payloads', false);
    }
}