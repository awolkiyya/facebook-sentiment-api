<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\FacebookComment;
use App\Models\SentimentAnalysis;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AIAnalysis
{
    /**
     * Store an AI analysis result for a Facebook comment.
     *
     * Model-version management is intentionally not handled here yet.
     *
     * @param array<string, mixed> $result
     *
     * @throws InvalidArgumentException
     */
    public function store(
        FacebookComment $comment,
        array $result,
    ): SentimentAnalysis {
        $this->validateComment($comment);

        $this->validateResult(
            $result,
            $comment,
        );

        return DB::transaction(
            function () use (
                $comment,
                $result,
            ): SentimentAnalysis {
                /*
                 * For the current MVP, one Facebook comment has
                 * one current AI analysis.
                 *
                 * Model-version-specific analysis history can be
                 * introduced later when model version management
                 * is implemented.
                 */
                $analysis = SentimentAnalysis::query()
                    ->where(
                        'facebook_comment_id',
                        $comment->id,
                    )
                    ->first();

                $data = $this->buildAnalysisData(
                    $comment,
                    $result,
                );

                if ($analysis === null) {
                    return SentimentAnalysis::create($data);
                }

                $analysis->fill($data);
                $analysis->save();

                return $analysis->refresh();
            }
        );
    }

    /**
     * Store multiple AI analysis results.
     *
     * Results are matched using external_id.
     *
     * Model-version management is intentionally not handled here yet.
     *
     * @param iterable<int, FacebookComment> $comments
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<int, SentimentAnalysis>
     *
     * @throws InvalidArgumentException
     */
    public function storeBatch(
        iterable $comments,
        array $results,
    ): array {
        $comments = is_array($comments)
            ? array_values($comments)
            : iterator_to_array($comments, false);

        if ($comments === []) {
            return [];
        }

        if ($results === []) {
            throw new InvalidArgumentException(
                'AI analysis results cannot be empty.'
            );
        }

        /*
         * Index comments by the internal Laravel UUID.
         *
         * The UUID is also the correlation ID sent to Python
         * as external_id/id.
         */
        $commentsById = [];

        foreach ($comments as $index => $comment) {
            if (!$comment instanceof FacebookComment) {
                throw new InvalidArgumentException(
                    "Item at index {$index} must be a FacebookComment."
                );
            }

            $this->validateComment($comment);

            $commentId = (string) $comment->id;

            if (isset($commentsById[$commentId])) {
                throw new InvalidArgumentException(
                    "Duplicate Facebook comment ID '{$commentId}' "
                    . 'was provided in the batch.'
                );
            }

            $commentsById[$commentId] = $comment;
        }

        /*
         * Every submitted comment must have exactly one AI result.
         */
        if (count($results) !== count($comments)) {
            throw new InvalidArgumentException(
                sprintf(
                    'AI returned %d results for %d comments.',
                    count($results),
                    count($comments),
                )
            );
        }

        return DB::transaction(
            function () use (
                $results,
                $commentsById,
            ): array {
                $analyses = [];

                /*
                 * Track processed IDs to prevent duplicate results.
                 */
                $processedCommentIds = [];

                foreach ($results as $index => $result) {
                    if (!is_array($result)) {
                        throw new InvalidArgumentException(
                            "AI result at index {$index} must be an array."
                        );
                    }

                    $externalId = $result['external_id'] ?? null;

                    if (
                        !is_string($externalId)
                        || trim($externalId) === ''
                    ) {
                        throw new InvalidArgumentException(
                            "AI result at index {$index} "
                            . 'has an invalid external_id.'
                        );
                    }

                    $externalId = trim($externalId);

                    /*
                     * Ensure the result belongs to a submitted comment.
                     */
                    if (!isset($commentsById[$externalId])) {
                        throw new InvalidArgumentException(
                            "AI result references unknown "
                            . "Facebook comment '{$externalId}'."
                        );
                    }

                    /*
                     * Prevent duplicate results for the same comment.
                     */
                    if (isset($processedCommentIds[$externalId])) {
                        throw new InvalidArgumentException(
                            "Duplicate AI result for Facebook comment "
                            . "'{$externalId}'."
                        );
                    }

                    $comment = $commentsById[$externalId];

                    /*
                     * Validate the complete mapped AI result.
                     */
                    $this->validateResult(
                        $result,
                        $comment,
                    );

                    /*
                     * Build the persistence payload.
                     */
                    $data = $this->buildAnalysisData(
                        $comment,
                        $result,
                    );

                    /*
                     * For the current MVP, locate the current analysis
                     * only by Facebook comment.
                     */
                    $analysis = SentimentAnalysis::query()
                        ->where(
                            'facebook_comment_id',
                            $comment->id,
                        )
                        ->first();

                    if ($analysis === null) {
                        $analysis = SentimentAnalysis::create(
                            $data,
                        );
                    } else {
                        $analysis->fill($data);
                        $analysis->save();
                        $analysis->refresh();
                    }

                    $analyses[] = $analysis;

                    $processedCommentIds[$externalId] = true;
                }

                /*
                 * Verify every submitted comment was processed.
                 */
                foreach ($commentsById as $commentId => $_comment) {
                    if (!isset($processedCommentIds[$commentId])) {
                        throw new InvalidArgumentException(
                            "No AI result was persisted for "
                            . "Facebook comment '{$commentId}'."
                        );
                    }
                }

                return $analyses;
            }
        );
    }

    /**
     * Check whether a comment already has an AI analysis.
     *
     * Model-version management is intentionally not handled here.
     */
    public function exists(
        FacebookComment $comment,
    ): bool {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where(
                'facebook_comment_id',
                $comment->id,
            )
            ->exists();
    }

    /**
     * Retrieve the current AI analysis for a comment.
     *
     * @return SentimentAnalysis|null
     */
    public function get(
        FacebookComment $comment,
    ): ?SentimentAnalysis {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where(
                'facebook_comment_id',
                $comment->id,
            )
            ->first();
    }

    /**
     * Retrieve the latest analysis for a comment.
     *
     * This remains useful if historical analysis records are introduced
     * later.
     */
    public function getLatest(
        FacebookComment $comment,
    ): ?SentimentAnalysis {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where(
                'facebook_comment_id',
                $comment->id,
            )
            ->latest('analyzed_at')
            ->first();
    }

    /**
     * Delete the current AI analysis for a comment.
     *
     * Model-version management is intentionally not handled here.
     */
    public function delete(
        FacebookComment $comment,
    ): bool {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where(
                'facebook_comment_id',
                $comment->id,
            )
            ->delete() > 0;
    }

    /**
     * Build the database payload for SentimentAnalysis.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function buildAnalysisData(
        FacebookComment $comment,
        array $result,
    ): array {
        return [
            'facebook_comment_id' => $comment->id,

            'language' => $result['language_code'],

            'sentiment' => $result['sentiment_label'],

            'confidence' => (float) $result['sentiment_confidence'],

            /*
             * Optional sentiment probability distribution.
             */
            'positive_score' => $this->optionalScore(
                $result,
                'positive_score',
            ),

            'neutral_score' => $this->optionalScore(
                $result,
                'neutral_score',
            ),

            'negative_score' => $this->optionalScore(
                $result,
                'negative_score',
            ),

            /*
             * Optional Python processing time.
             */
            'processing_time_ms' => $this->optionalInteger(
                $result,
                'processing_time_ms',
            ),

            /*
             * Python can provide analyzed_at later.
             * Laravel uses now() when it is not provided.
             */
            'analyzed_at' => $this->analyzedAt(
                $result,
            ),

            /*
             * Store the normalized AI prediction for auditing/debugging.
             */
            'raw_prediction' => $result,
        ];
    }

    /**
     * Validate the Facebook comment.
     */
    private function validateComment(
        FacebookComment $comment,
    ): void {
        if (!$comment->exists) {
            throw new InvalidArgumentException(
                'Facebook comment must exist before AI analysis.'
            );
        }

        if ($comment->id === null) {
            throw new InvalidArgumentException(
                'Facebook comment must have an ID.'
            );
        }

        if ($comment->message === null) {
            throw new InvalidArgumentException(
                'Facebook comment has no message to analyze.'
            );
        }

        if (trim($comment->message) === '') {
            throw new InvalidArgumentException(
                'Facebook comment message cannot be empty.'
            );
        }
    }

    /**
     * Validate a mapped AI result before persistence.
     *
     * @param array<string, mixed> $result
     */
    private function validateResult(
        array $result,
        FacebookComment $comment,
    ): void {
        $requiredFields = [
            'external_id',
            'language_code',
            'language_confidence',
            'sentiment_label',
            'sentiment_confidence',
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $result)) {
                throw new InvalidArgumentException(
                    "AI result for comment '{$comment->id}' "
                    . "is missing required field '{$field}'."
                );
            }
        }

        /*
         * Correlation ID.
         */
        if (
            !is_string($result['external_id'])
            || trim($result['external_id']) === ''
        ) {
            throw new InvalidArgumentException(
                "Invalid external_id for comment '{$comment->id}'."
            );
        }

        if (
            (string) $result['external_id']
            !== (string) $comment->id
        ) {
            throw new InvalidArgumentException(
                "AI result does not belong to Facebook comment "
                . "'{$comment->id}'."
            );
        }

        /*
         * Language.
         */
        if (
            !is_string($result['language_code'])
            || trim($result['language_code']) === ''
        ) {
            throw new InvalidArgumentException(
                "Invalid language_code for comment '{$comment->id}'."
            );
        }

        $language = strtolower(
            trim($result['language_code'])
        );

        $allowedLanguages = [
            'am',
            'om',
            'en',
        ];

        if (!in_array($language, $allowedLanguages, true)) {
            throw new InvalidArgumentException(
                "Unsupported language '{$language}' "
                . "for comment '{$comment->id}'."
            );
        }

        /*
         * Sentiment.
         */
        if (
            !is_string($result['sentiment_label'])
            || trim($result['sentiment_label']) === ''
        ) {
            throw new InvalidArgumentException(
                "Invalid sentiment_label for comment '{$comment->id}'."
            );
        }

        $sentiment = strtolower(
            trim($result['sentiment_label'])
        );

        $allowedSentiments = [
            'negative',
            'neutral',
            'positive',
        ];

        if (!in_array($sentiment, $allowedSentiments, true)) {
            throw new InvalidArgumentException(
                "Unsupported sentiment '{$sentiment}' "
                . "for comment '{$comment->id}'."
            );
        }

        /*
         * Confidence values must be between 0 and 1.
         */
        $this->validateConfidence(
            $result['language_confidence'],
            'language_confidence',
            $comment,
        );

        $this->validateConfidence(
            $result['sentiment_confidence'],
            'sentiment_confidence',
            $comment,
        );

        /*
         * Optional probability scores.
         */
        $optionalScores = [
            'positive_score',
            'neutral_score',
            'negative_score',
        ];

        foreach ($optionalScores as $field) {
            if (
                array_key_exists($field, $result)
                && $result[$field] !== null
            ) {
                $this->validateConfidence(
                    $result[$field],
                    $field,
                    $comment,
                );
            }
        }

        /*
         * Optional processing time.
         */
        if (
            array_key_exists('processing_time_ms', $result)
            && $result['processing_time_ms'] !== null
        ) {
            $this->validateProcessingTime(
                $result['processing_time_ms'],
                $comment,
            );
        }
    }

    /**
     * Validate an AI confidence/probability score.
     */
    private function validateConfidence(
        mixed $value,
        string $field,
        FacebookComment $comment,
    ): void {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                "AI {$field} must be numeric "
                . "for comment '{$comment->id}'."
            );
        }

        $confidence = (float) $value;

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException(
                "AI {$field} must be between 0 and 1 "
                . "for comment '{$comment->id}'."
            );
        }
    }

    /**
     * Validate processing time.
     */
    private function validateProcessingTime(
        mixed $value,
        FacebookComment $comment,
    ): void {
        if (
            is_int($value)
            || (
                is_string($value)
                && filter_var(
                    $value,
                    FILTER_VALIDATE_INT,
                ) !== false
            )
        ) {
            if ((int) $value < 0) {
                throw new InvalidArgumentException(
                    "AI processing_time_ms cannot be negative "
                    . "for comment '{$comment->id}'."
                );
            }

            return;
        }

        if (
            is_float($value)
            && floor($value) === $value
        ) {
            if ($value < 0) {
                throw new InvalidArgumentException(
                    "AI processing_time_ms cannot be negative "
                    . "for comment '{$comment->id}'."
                );
            }

            return;
        }

        throw new InvalidArgumentException(
            "AI processing_time_ms must be an integer "
            . "for comment '{$comment->id}'."
        );
    }

    /**
     * Read an optional probability score.
     *
     * @param array<string, mixed> $result
     */
    private function optionalScore(
        array $result,
        string $field,
    ): ?float {
        if (
            !array_key_exists($field, $result)
            || $result[$field] === null
        ) {
            return null;
        }

        return (float) $result[$field];
    }

    /**
     * Read an optional integer.
     *
     * @param array<string, mixed> $result
     */
    private function optionalInteger(
        array $result,
        string $field,
    ): ?int {
        if (
            !array_key_exists($field, $result)
            || $result[$field] === null
        ) {
            return null;
        }

        $value = $result[$field];

        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && filter_var(
                $value,
                FILTER_VALIDATE_INT,
            ) !== false
        ) {
            return (int) $value;
        }

        if (
            is_float($value)
            && floor($value) === $value
        ) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "AI field '{$field}' must be an integer."
        );
    }

    /**
     * Resolve analyzed_at from the AI result.
     *
     * If Python does not provide analyzed_at yet,
     * Laravel uses the current application time.
     *
     * @param array<string, mixed> $result
     */
    private function analyzedAt(
        array $result,
    ): mixed {
        if (
            !array_key_exists('analyzed_at', $result)
            || $result['analyzed_at'] === null
        ) {
            return now();
        }

        if (!is_string($result['analyzed_at'])) {
            throw new InvalidArgumentException(
                'AI analyzed_at must be a valid date string.'
            );
        }

        $value = trim($result['analyzed_at']);

        if ($value === '') {
            throw new InvalidArgumentException(
                'AI analyzed_at cannot be empty.'
            );
        }

        return $value;
    }
}
