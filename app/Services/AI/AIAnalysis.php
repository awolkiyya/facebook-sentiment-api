<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\FacebookComment;
use App\Models\SentimentAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AIAnalysis
{
    private const ALLOWED_LANGUAGES = [
        'am',
        'om',
        'en',
    ];

    private const ALLOWED_SENTIMENTS = [
        'negative',
        'neutral',
        'positive',
    ];

    /**
     * Store or update the current analysis for a comment.
     *
     * @param array<string, mixed> $result
     */
    public function store(
        FacebookComment $comment,
        array $result,
    ): SentimentAnalysis {
        $this->validateComment($comment);
        $this->validateResult($result, $comment);

        $data = $this->buildAnalysisData($comment, $result);

        return DB::transaction(function () use (
            $comment,
            $data,
        ): SentimentAnalysis {
            $analysis = SentimentAnalysis::query()
                ->where('facebook_comment_id', $comment->id)
                ->lockForUpdate()
                ->first();

            if ($analysis === null) {
                return SentimentAnalysis::query()->create($data);
            }

            $analysis->fill($data);
            $analysis->save();

            return $analysis->refresh();
        }, 3);
    }

    /**
     * Store or update a batch of comment analyses.
     *
     * Every submitted comment must have exactly one corresponding
     * result, matched by external_id.
     *
     * @param iterable<int, FacebookComment> $comments
     * @param array<int, array<string, mixed>> $results
     * @return array<int, SentimentAnalysis>
     */
    public function storeBatch(
        iterable $comments,
        array $results,
    ): array {
        $comments = is_array($comments)
            ? array_values($comments)
            : iterator_to_array($comments, false);

        if ($comments === []) {
            if ($results !== []) {
                throw new InvalidArgumentException(
                    'AI results were supplied without comments.'
                );
            }

            return [];
        }

        if ($results === []) {
            throw new InvalidArgumentException(
                'AI analysis results cannot be empty.'
            );
        }

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
                    "Duplicate Facebook comment ID '{$commentId}'."
                );
            }

            $commentsById[$commentId] = $comment;
        }

        if (count($results) !== count($commentsById)) {
            throw new InvalidArgumentException(
                sprintf(
                    'AI returned %d results for %d comments.',
                    count($results),
                    count($commentsById),
                )
            );
        }

        /*
         * Validate and prepare every result before writing anything.
         */
        $prepared = [];
        $seenIds = [];

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
                    "AI result at index {$index} has an invalid external_id."
                );
            }

            $externalId = trim($externalId);

            if (!isset($commentsById[$externalId])) {
                throw new InvalidArgumentException(
                    "AI result references unknown comment '{$externalId}'."
                );
            }

            if (isset($seenIds[$externalId])) {
                throw new InvalidArgumentException(
                    "Duplicate AI result for comment '{$externalId}'."
                );
            }

            $comment = $commentsById[$externalId];

            $this->validateResult($result, $comment);

            $prepared[$externalId] = [
                'comment' => $comment,
                'data' => $this->buildAnalysisData($comment, $result),
            ];

            $seenIds[$externalId] = true;
        }

        foreach ($commentsById as $commentId => $_comment) {
            if (!isset($seenIds[$commentId])) {
                throw new InvalidArgumentException(
                    "No AI result was provided for comment '{$commentId}'."
                );
            }
        }

        return DB::transaction(function () use (
            $prepared,
            $commentsById,
        ): array {
            $analysesById = [];

            /*
             * Sort IDs to make row-lock acquisition order consistent
             * across batch requests and reduce deadlock risk.
             */
            $ids = array_keys($commentsById);
            sort($ids, SORT_STRING);

            foreach ($ids as $commentId) {
                $comment = $prepared[$commentId]['comment'];
                $data = $prepared[$commentId]['data'];

                $analysis = SentimentAnalysis::query()
                    ->where('facebook_comment_id', $comment->id)
                    ->lockForUpdate()
                    ->first();

                if ($analysis === null) {
                    $analysis = SentimentAnalysis::query()
                        ->create($data);
                } else {
                    $analysis->fill($data);
                    $analysis->save();
                    $analysis->refresh();
                }

                $analysesById[$commentId] = $analysis;
            }

            /*
             * Preserve the caller's result order.
             */
            $ordered = [];

            foreach ($prepared as $commentId => $_item) {
                $ordered[] = $analysesById[$commentId];
            }

            return $ordered;
        }, 3);
    }

    /**
     * Check whether an analysis exists.
     */
    public function exists(FacebookComment $comment): bool
    {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where('facebook_comment_id', $comment->id)
            ->exists();
    }

    /**
     * Retrieve the current analysis.
     */
    public function get(FacebookComment $comment): ?SentimentAnalysis
    {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where('facebook_comment_id', $comment->id)
            ->first();
    }

    /**
     * Retrieve the latest analysis.
     */
    public function getLatest(FacebookComment $comment): ?SentimentAnalysis
    {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where('facebook_comment_id', $comment->id)
            ->latest('analyzed_at')
            ->first();
    }

    /**
     * Delete the current analysis.
     */
    public function delete(FacebookComment $comment): bool
    {
        $this->validateComment($comment);

        return SentimentAnalysis::query()
            ->where('facebook_comment_id', $comment->id)
            ->delete() > 0;
    }

    /**
     * Build the database payload.
     *
     * Mapper keys are intentionally mapped to database column names here:
     * topic_label -> topic
     * priority_label -> priority
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildAnalysisData(
        FacebookComment $comment,
        array $result,
    ): array {
        return [
            'facebook_comment_id' => $comment->id,

            'language' => strtolower(trim($result['language_code'])),

            'sentiment' => strtolower(trim($result['sentiment_label'])),

            'confidence' => (float) $result['sentiment_confidence'],

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

            'topic' => $this->optionalLabel(
                $result,
                'topic_label',
            ),

            'topic_confidence' => $this->optionalScore(
                $result,
                'topic_confidence',
            ),

            'priority' => $this->optionalLabel(
                $result,
                'priority_label',
            ),

            'priority_confidence' => $this->optionalScore(
                $result,
                'priority_confidence',
            ),

            'processing_time_ms' => $this->optionalInteger(
                $result,
                'processing_time_ms',
            ),

            'analyzed_at' => $this->analyzedAt($result),

            'raw_prediction' => $result,
        ];
    }

    /**
     * Validate the comment before analysis.
     */
    private function validateComment(FacebookComment $comment): void
    {
        if (!$comment->exists || $comment->id === null) {
            throw new InvalidArgumentException(
                'Facebook comment must exist and have an ID.'
            );
        }

        if (
            $comment->message === null
            || trim($comment->message) === ''
        ) {
            throw new InvalidArgumentException(
                "Facebook comment '{$comment->id}' has no message to analyze."
            );
        }
    }

    /**
     * Validate a mapped AI result.
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
            if (
                !array_key_exists($field, $result)
                || $result[$field] === null
            ) {
                throw new InvalidArgumentException(
                    "AI result for '{$comment->id}' is missing '{$field}'."
                );
            }
        }

        if (
            !is_string($result['external_id'])
            || trim($result['external_id']) !== (string) $comment->id
        ) {
            throw new InvalidArgumentException(
                "AI result does not match comment '{$comment->id}'."
            );
        }

        if (
            !is_string($result['language_code'])
            || !in_array(
                strtolower(trim($result['language_code'])),
                self::ALLOWED_LANGUAGES,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Unsupported language for comment '{$comment->id}'."
            );
        }

        if (
            !is_string($result['sentiment_label'])
            || !in_array(
                strtolower(trim($result['sentiment_label'])),
                self::ALLOWED_SENTIMENTS,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Unsupported sentiment for comment '{$comment->id}'."
            );
        }

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

        foreach ([
            'positive_score',
            'neutral_score',
            'negative_score',
            'topic_confidence',
            'priority_confidence',
        ] as $field) {
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

        foreach (['topic_label', 'priority_label'] as $field) {
            if (
                array_key_exists($field, $result)
                && $result[$field] !== null
                && (
                    !is_string($result[$field])
                    || trim($result[$field]) === ''
                )
            ) {
                throw new InvalidArgumentException(
                    "AI {$field} must be a non-empty string or null "
                    . "for comment '{$comment->id}'."
                );
            }
        }

        if (
            array_key_exists('processing_time_ms', $result)
            && $result['processing_time_ms'] !== null
        ) {
            $this->validateProcessingTime(
                $result['processing_time_ms'],
                $comment,
            );
        }

        /*
         * Validate timestamps before any database writes.
         */
        $this->analyzedAt($result);
    }

    /**
     * Validate a confidence or probability value.
     */
    private function validateConfidence(
        mixed $value,
        string $field,
        FacebookComment $comment,
    ): void {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException(
                "AI {$field} must be a finite numeric value "
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
     * Validate a non-negative integer processing time.
     */
    private function validateProcessingTime(
        mixed $value,
        FacebookComment $comment,
    ): void {
        if (
            !is_int($value)
            && !(
                is_string($value)
                && filter_var($value, FILTER_VALIDATE_INT) !== false
            )
            && !(
                is_float($value)
                && is_finite($value)
                && floor($value) === $value
            )
        ) {
            throw new InvalidArgumentException(
                "AI processing_time_ms must be an integer "
                . "for comment '{$comment->id}'."
            );
        }

        if ((float) $value < 0) {
            throw new InvalidArgumentException(
                "AI processing_time_ms cannot be negative "
                . "for comment '{$comment->id}'."
            );
        }
    }

    /**
     * Read and validate an optional label.
     *
     * @param array<string, mixed> $result
     */
    private function optionalLabel(
        array $result,
        string $field,
    ): ?string {
        if (
            !array_key_exists($field, $result)
            || $result[$field] === null
        ) {
            return null;
        }

        if (
            !is_string($result[$field])
            || trim($result[$field]) === ''
        ) {
            throw new InvalidArgumentException(
                "AI field '{$field}' must be a non-empty string or null."
            );
        }

        return trim($result[$field]);
    }

    /**
     * Read an optional probability/confidence value.
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

        if (
            !is_numeric($result[$field])
            || !is_finite((float) $result[$field])
        ) {
            throw new InvalidArgumentException(
                "AI field '{$field}' must be numeric or null."
            );
        }

        $score = (float) $result[$field];

        if ($score < 0.0 || $score > 1.0) {
            throw new InvalidArgumentException(
                "AI field '{$field}' must be between 0 and 1."
            );
        }

        return $score;
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
            && filter_var($value, FILTER_VALIDATE_INT) !== false
        ) {
            return (int) $value;
        }

        if (
            is_float($value)
            && is_finite($value)
            && floor($value) === $value
            && $value <= PHP_INT_MAX
            && $value >= PHP_INT_MIN
        ) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "AI field '{$field}' must be an integer or null."
        );
    }

    /**
     * Resolve and validate the analysis timestamp.
     *
     * @param array<string, mixed> $result
     */
    private function analyzedAt(array $result): Carbon
    {
        if (
            !array_key_exists('analyzed_at', $result)
            || $result['analyzed_at'] === null
        ) {
            return now();
        }

        $value = $result['analyzed_at'];

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                'AI analyzed_at must be a non-empty date string or null.'
            );
        }

        try {
            return Carbon::parse(trim($value));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'AI analyzed_at contains an invalid date.',
                previous: $exception,
            );
        }
    }
}
