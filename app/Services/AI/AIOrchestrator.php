<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\FacebookComment;
use App\Models\SentimentAnalysis;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AIOrchestrator
{
    public function __construct(
        private readonly AIClient $aiClient,
        private readonly AIResultMapper $resultMapper,
        private readonly AIAnalysis $aiAnalysis,
    ) {
    }

    /**
     * Analyze a single Facebook comment.
     *
     * Workflow:
     *
     * 1. Validate comment
     * 2. Prepare comment text
     * 3. Send comment to Python AI service
     * 4. Map AI response
     * 5. Validate AI result
     * 6. Persist analysis
     * 7. Return persisted analysis
     *
     * Model-version management is intentionally not handled here.
     *
     * @throws AIException
     * @throws InvalidArgumentException
     */
    public function analyzeComment(
        FacebookComment $comment
    ): SentimentAnalysis {
        $this->validateComment($comment);

        $commentId = (string) $comment->id;

        try {
            /*
             * Prepare the AI request.
             *
             * The internal Laravel FacebookComment UUID is used
             * as the correlation ID between Laravel and Python.
             */
            $items = [
                [
                    'id' => $commentId,
                    'text' => $this->prepareText($comment->message),
                ],
            ];

            Log::debug(
                'AI orchestrator | Single comment analysis started.',
                [
                    'comment_id' => $commentId,
                    'items_count' => 1,
                ]
            );

            /*
             * Send the comment to the Python AI service.
             */
            $response = $this->aiClient->analyzeBatch($items);

            Log::debug(
                'AI orchestrator | Single comment AI response received.',
                [
                    'comment_id' => $commentId,
                    'response_type' => get_debug_type($response),
                ]
            );

            /*
             * Convert the Python response into the internal
             * Laravel AI result structure.
             */
            $results = $this->resultMapper->mapBatch($response);

            Log::debug(
                'AI orchestrator | Single comment AI response mapped.',
                [
                    'comment_id' => $commentId,
                    'results_count' => count($results),
                ]
            );

            /*
             * Exactly one result is required for one comment.
             */
            if (count($results) !== 1) {
                throw new AIException(
                    sprintf(
                        'AI service returned %d results for 1 comment.',
                        count($results),
                    )
                );
            }

            $result = $results[0];

            /*
             * Verify that the returned result belongs
             * to the requested Facebook comment.
             */
            if (
                !isset($result['external_id'])
                || !is_string($result['external_id'])
                || trim($result['external_id']) === ''
            ) {
                throw new AIException(
                    'AI service returned a result without a valid external_id.'
                );
            }

            $externalId = trim($result['external_id']);

            if ($externalId !== $commentId) {
                throw new AIException(
                    sprintf(
                        'AI service returned result for unexpected comment "%s".',
                        $externalId,
                    )
                );
            }

            /*
             * Persist the analysis.
             *
             * Model versions are intentionally not passed.
             */
            $analysis = $this->aiAnalysis->store(
                $comment,
                $result,
            );

            Log::info(
                'AI orchestrator | Single comment analysis completed.',
                [
                    'comment_id' => $commentId,
                    'analysis_id' => (string) $analysis->id,
                ]
            );

            return $analysis;
        } catch (AIException $exception) {
            Log::warning(
                'AI orchestrator | Single comment analysis failed.',
                [
                    'comment_id' => $commentId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'previous_exception_class' => $this->previousExceptionClass(
                        $exception
                    ),
                    'previous_exception_message' => $this->previousExceptionMessage(
                        $exception
                    ),
                ]
            );

            throw $exception;
        } catch (InvalidArgumentException $exception) {
            Log::warning(
                'AI orchestrator | Invalid single comment analysis input.',
                [
                    'comment_id' => $commentId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                ]
            );

            throw $exception;
        } catch (Throwable $exception) {
            Log::error(
                'AI orchestrator | Unexpected single comment analysis failure.',
                [
                    'comment_id' => $commentId,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'previous_exception_class' => $this->previousExceptionClass(
                        $exception
                    ),
                    'previous_exception_message' => $this->previousExceptionMessage(
                        $exception
                    ),
                ]
            );

            throw new AIException(
                message: 'Failed to analyze Facebook comment.',
                previous: $exception,
            );
        }
    }

    /**
     * Analyze multiple Facebook comments using one AI request.
     *
     * This is the preferred method for queue-based processing.
     *
     * Workflow:
     *
     * 1. Normalize iterable
     * 2. Validate every comment
     * 3. Prepare AI batch
     * 4. Send one request to Python
     * 5. Map AI response
     * 6. Validate result count
     * 7. Validate result IDs
     * 8. Persist complete batch
     *
     * Model-version management is intentionally not handled here.
     *
     * @param iterable<int, FacebookComment> $comments
     * @return array<int, SentimentAnalysis>
     *
     * @throws AIException
     * @throws InvalidArgumentException
     */
    public function analyzeComments(
        iterable $comments
    ): array {
        /*
         * Normalize the iterable so that all later operations
         * work against a stable indexed array.
         */
        $comments = is_array($comments)
            ? array_values($comments)
            : iterator_to_array($comments, false);

        if ($comments === []) {
            Log::debug(
                'AI orchestrator | Batch analysis skipped because no comments were provided.'
            );

            return [];
        }

        $commentIds = [];

        try {
            /*
             * Prepare the complete AI request batch.
             */
            $items = [];

            foreach ($comments as $index => $comment) {
                if (!$comment instanceof FacebookComment) {
                    throw new InvalidArgumentException(
                        "Item at index {$index} must be a FacebookComment."
                    );
                }

                $this->validateComment($comment);

                $commentId = (string) $comment->id;

                $commentIds[] = $commentId;

                $items[] = [
                    'id' => $commentId,
                    'text' => $this->prepareText($comment->message),
                ];
            }

            Log::info(
                'AI orchestrator | Batch analysis started.',
                [
                    'comments_count' => count($comments),
                    'comment_ids_count' => count($commentIds),
                ]
            );

            /*
             * Send the complete batch to Python.
             */
            $response = $this->aiClient->analyzeBatch($items);

            Log::debug(
                'AI orchestrator | Batch AI response received.',
                [
                    'comments_count' => count($comments),
                    'response_type' => get_debug_type($response),
                ]
            );

            /*
             * Map the Python response into the internal
             * Laravel result structure.
             */
            $results = $this->resultMapper->mapBatch($response);

            Log::debug(
                'AI orchestrator | Batch AI response mapped.',
                [
                    'comments_count' => count($comments),
                    'results_count' => count($results),
                ]
            );

            /*
             * Every submitted comment must receive exactly
             * one AI result.
             */
            if (count($results) !== count($comments)) {
                throw new AIException(
                    sprintf(
                        'AI service returned %d results for %d comments.',
                        count($results),
                        count($comments),
                    )
                );
            }

            /*
             * Verify:
             *
             * - every result has an external_id
             * - every external_id belongs to a submitted comment
             * - no duplicate result exists
             * - no submitted comment is missing a result
             */
            $this->validateBatchResultIds(
                $comments,
                $results,
            );

            Log::debug(
                'AI orchestrator | Batch AI result validation completed.',
                [
                    'comments_count' => count($comments),
                    'results_count' => count($results),
                ]
            );

            /*
             * Persist the complete batch.
             *
             * AIAnalysis owns database persistence and transactions.
             *
             * Model versions are intentionally not passed.
             */
            $analyses = $this->aiAnalysis->storeBatch(
                $comments,
                $results,
            );

            Log::info(
                'AI orchestrator | Batch analysis completed.',
                [
                    'comments_count' => count($comments),
                    'results_count' => count($results),
                    'analyses_count' => count($analyses),
                ]
            );

            return $analyses;
        } catch (AIException $exception) {
            Log::warning(
                'AI orchestrator | Batch analysis failed.',
                [
                    'comments_count' => count($comments),
                    'comment_ids_count' => count($commentIds),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'previous_exception_class' => $this->previousExceptionClass(
                        $exception
                    ),
                    'previous_exception_message' => $this->previousExceptionMessage(
                        $exception
                    ),
                ]
            );

            throw $exception;
        } catch (InvalidArgumentException $exception) {
            Log::warning(
                'AI orchestrator | Invalid batch analysis input.',
                [
                    'comments_count' => count($comments),
                    'comment_ids_count' => count($commentIds),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                ]
            );

            throw $exception;
        } catch (Throwable $exception) {
            Log::error(
                'AI orchestrator | Unexpected batch analysis failure.',
                [
                    'comments_count' => count($comments),
                    'comment_ids_count' => count($commentIds),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'previous_exception_class' => $this->previousExceptionClass(
                        $exception
                    ),
                    'previous_exception_message' => $this->previousExceptionMessage(
                        $exception
                    ),
                ]
            );

            throw new AIException(
                message: 'Failed to analyze Facebook comments.',
                previous: $exception,
            );
        }
    }

    /**
     * Verify that every AI result belongs to one of the submitted
     * Facebook comments and that there are no duplicate results.
     *
     * @param array<int, FacebookComment> $comments
     * @param array<int, array<string, mixed>> $results
     *
     * @throws AIException
     */
    private function validateBatchResultIds(
        array $comments,
        array $results,
    ): void {
        $commentIds = [];

        foreach ($comments as $comment) {
            $commentIds[(string) $comment->id] = true;
        }

        $resultIds = [];

        foreach ($results as $index => $result) {
            $externalId = $result['external_id'] ?? null;

            /*
             * external_id is the correlation key returned by Python.
             */
            if (
                !is_string($externalId)
                || trim($externalId) === ''
            ) {
                throw new AIException(
                    "AI result at index {$index} has an invalid external_id."
                );
            }

            $externalId = trim($externalId);

            /*
             * Prevent duplicate AI results.
             */
            if (isset($resultIds[$externalId])) {
                throw new AIException(
                    "AI service returned duplicate result "
                    . "for comment '{$externalId}'."
                );
            }

            /*
             * Prevent results belonging to another comment.
             */
            if (!isset($commentIds[$externalId])) {
                throw new AIException(
                    "AI service returned a result for unknown "
                    . "Facebook comment '{$externalId}'."
                );
            }

            $resultIds[$externalId] = true;
        }

        /*
         * Ensure every submitted comment received exactly
         * one result.
         */
        foreach ($commentIds as $commentId => $_) {
            if (!isset($resultIds[$commentId])) {
                throw new AIException(
                    "AI service did not return a result "
                    . "for Facebook comment '{$commentId}'."
                );
            }
        }
    }

    /**
     * Prepare Facebook comment text before AI inference.
     *
     * The original Facebook message remains unchanged in the database.
     * This method only prepares the text sent to AI.
     */
    private function prepareText(?string $text): string
    {
        if ($text === null) {
            throw new InvalidArgumentException(
                'Facebook comment message cannot be null.'
            );
        }

        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException(
                'Facebook comment message cannot be empty.'
            );
        }

        return $text;
    }

    /**
     * Validate a Facebook comment before AI processing.
     */
    private function validateComment(
        FacebookComment $comment
    ): void {
        if (!$comment->exists) {
            throw new InvalidArgumentException(
                'Facebook comment must exist in the database before analysis.'
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
     * Get the previous exception class for diagnostics.
     */
    private function previousExceptionClass(
        Throwable $exception
    ): ?string {
        $previous = $exception->getPrevious();

        return $previous !== null
            ? $previous::class
            : null;
    }

    /**
     * Get the previous exception message for diagnostics.
     *
     * This is intentionally limited to exception messages and does not
     * log request payloads, API keys, or Facebook comment content.
     */
    private function previousExceptionMessage(
        Throwable $exception
    ): ?string {
        $previous = $exception->getPrevious();

        return $previous !== null
            ? $previous->getMessage()
            : null;
    }
}
