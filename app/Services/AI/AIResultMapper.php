<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

class AIResultMapper
{
    /**
     * Map the complete AI batch response into a Laravel-friendly structure.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function mapBatch(array $response): array
    {
        $results = $response['results'] ?? null;

        if (!is_array($results)) {
            throw new InvalidArgumentException(
                'AI response is missing a valid results array.'
            );
        }

        return array_map(
            function (mixed $result, int $index): array {
                if (!is_array($result)) {
                    throw new InvalidArgumentException(
                        "AI result at index {$index} must be an array."
                    );
                }

                return $this->mapResult($result, $index);
            },
            $results,
            array_keys($results),
        );
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
            "AI result at index {$index}"
        );

        $language = $this->requiredArray(
            $result,
            'language',
            "AI result '{$id}'"
        );

        $sentiment = $this->requiredArray(
            $result,
            'sentiment',
            "AI result '{$id}'"
        );

        $mapped = [
            'external_id' => $id,

            /*
             * Language
             */
            'language_code' => $this->requiredString(
                $language,
                'code',
                "AI language result for '{$id}'"
            ),

            'language_confidence' => $this->confidence(
                $language,
                'confidence',
                "AI language result for '{$id}'"
            ),

            /*
             * Sentiment
             */
            'sentiment_label' => $this->requiredString(
                $sentiment,
                'label',
                "AI sentiment result for '{$id}'"
            ),

            'sentiment_label_id' => $this->optionalInteger(
                $sentiment,
                'label_id',
            ),

            'sentiment_confidence' => $this->confidence(
                $sentiment,
                'confidence',
                "AI sentiment result for '{$id}'"
            ),

            /*
             * Topic
             *
             * Optional until TopicService is finalized.
             */
            'topic_label' => null,
            'topic_confidence' => null,

            /*
             * Priority
             *
             * Optional until PriorityService is finalized.
             */
            'priority_label' => null,
            'priority_confidence' => null,
        ];

        /*
         * Topic
         */
        if (isset($result['topic'])) {
            $topic = $this->optionalArray(
                $result['topic'],
                "AI topic result for '{$id}'"
            );

            if ($topic !== null) {
                $mapped['topic_label'] = $this->requiredString(
                    $topic,
                    'label',
                    "AI topic result for '{$id}'"
                );

                $mapped['topic_confidence'] = $this->confidence(
                    $topic,
                    'confidence',
                    "AI topic result for '{$id}'"
                );
            }
        }

        /*
         * Priority
         */
        if (isset($result['priority'])) {
            $priority = $this->optionalArray(
                $result['priority'],
                "AI priority result for '{$id}'"
            );

            if ($priority !== null) {
                $mapped['priority_label'] = $this->requiredString(
                    $priority,
                    'label',
                    "AI priority result for '{$id}'"
                );

                $mapped['priority_confidence'] = $this->confidence(
                    $priority,
                    'confidence',
                    "AI priority result for '{$id}'"
                );
            }
        }

        return $mapped;
    }

    /**
     * Extract a required string value.
     *
     * @param array<string, mixed> $data
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
     * Extract a required array.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
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
     * Extract an optional array.
     *
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function optionalArray(
        mixed $value,
        string $context,
    ): ?array {
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "{$context} must be an object."
            );
        }

        return $value;
    }

    /**
     * Extract and validate a confidence score.
     *
     * Confidence must be between 0 and 1.
     *
     * @param array<string, mixed> $data
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

        if ($confidence < 0.0 || $confidence > 1.0) {
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

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "AI field '{$key}' must be an integer."
        );
    }
}