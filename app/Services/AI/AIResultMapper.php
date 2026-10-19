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
     * Logs classification metadata without logging comment messages or
     * the complete AI response payload.
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

        /*
         * Inspect the actual AI response without logging comment messages.
         */
        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                continue;
            }

            $language = is_array($result['language'] ?? null)
                ? $result['language']
                : [];

            $sentiment = is_array($result['sentiment'] ?? null)
                ? $result['sentiment']
                : [];

            $scores = is_array($sentiment['scores'] ?? null)
                ? $sentiment['scores']
                : null;

            $topic = $result['topic'] ?? null;
            $priority = $result['priority'] ?? null;

            Log::info(
                'AIResultMapper | Actual AI result inspection.',
                [
                    'index' => $index,
                    'external_id' => $result['id'] ?? null,
                    'result_fields' => array_keys($result),

                    'language' => [
                        'type' => get_debug_type(
                            $result['language'] ?? null
                        ),
                        'code' => $language['code'] ?? null,
                        'confidence' => $language['confidence'] ?? null,
                    ],

                    'sentiment' => [
                        'type' => get_debug_type(
                            $result['sentiment'] ?? null
                        ),
                        'label' => $sentiment['label'] ?? null,
                        'label_id' => $sentiment['label_id'] ?? null,
                        'confidence' => $sentiment['confidence'] ?? null,
                        'scores' => $scores,
                        'scores_type' => get_debug_type(
                            $sentiment['scores'] ?? null
                        ),
                    ],

                    'topic' => [
                        'type' => get_debug_type($topic),
                        'label' => $this->optionalLabel($topic),
                        'confidence' => $this->optionalConfidence($topic),
                        'topic_id' => is_array($topic)
                            ? ($topic['topic_id'] ?? null)
                            : null,
                    ],

                    'priority' => [
                        'type' => get_debug_type($priority),
                        'label' => $this->optionalLabel($priority),
                        'confidence' => $this->optionalConfidence(
                            $priority
                        ),
                    ],

                    'processing_time_ms' =>
                        $result['processing_time_ms'] ?? null,

                    'model_version' =>
                        $result['model_version'] ?? null,

                    'model_version_id' =>
                        $result['model_version_id'] ?? null,
                ]
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
                    ]
                );

                throw new InvalidArgumentException(
                    sprintf(
                        'AI result at index %s must be an array.',
                        (string) $index,
                    )
                );
            }

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
                        'language_code' => $mappedResult['language_code'],
                        'sentiment_label' =>
                            $mappedResult['sentiment_label'],
                        'sentiment_confidence' =>
                            $mappedResult['sentiment_confidence'],

                        'positive_score' =>
                            $mappedResult['positive_score'],
                        'neutral_score' =>
                            $mappedResult['neutral_score'],
                        'negative_score' =>
                            $mappedResult['negative_score'],

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

        $scores = is_array($sentiment['scores'] ?? null)
            ? $sentiment['scores']
            : [];

        return [
            'external_id' => $id,

            /*
             * Language classification
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
             * Sentiment probability scores.
             *
             * These remain null when the AI service does not return them.
             * Sentiment confidence is not a substitute for these scores.
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
             * Topic classification.
             *
             * Supports both:
             * ['label' => 'roads_infrastructure']
             * ['topic' => 'roads_infrastructure', 'topic_id' => 1]
             */
            'topic_label' => $this->optionalLabel(
                $result['topic'] ?? null,
            ),

            'topic_confidence' => $this->optionalConfidence(
                $result['topic'] ?? null,
            ),

            /*
             * Priority classification.
             *
             * Optional until the AI service returns this field.
             */
            'priority_label' => $this->optionalLabel(
                $result['priority'] ?? null,
            ),

            'priority_confidence' => $this->optionalConfidence(
                $result['priority'] ?? null,
            ),

            /*
             * Processing metadata
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
     * Accepts:
     * - "public_services"
     * - ['label' => 'public_services']
     * - ['topic' => 'public_services', 'topic_id' => 18]
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

        /*
         * The AI topic response uses "topic"; other classifiers may use
         * "label". Prefer a non-empty label and fall back to topic.
         */
        $label = $value['label'] ?? null;

        if (!is_string($label) || trim($label) === '') {
            $label = $value['topic'] ?? null;
        }

        if (!is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label !== '' ? $label : null;
    }

    /**
     * Extract optional classification confidence.
     */
    private function optionalConfidence(mixed $value): ?float
    {
        if (
            !is_array($value)
            || !array_key_exists('confidence', $value)
        ) {
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
}
