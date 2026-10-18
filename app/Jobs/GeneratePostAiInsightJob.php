<?php

namespace App\Jobs;

use App\Models\PostAiInsight;
use App\Modules\Insights\Services\FacebookInsightDataService;
use App\Modules\Insights\Services\InsightNarrativeGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class GeneratePostAiInsightJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public array $backoff = [10, 30, 60];

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $insightId,
        public readonly bool $forceRegeneration = false,
    ) {
        $this->onQueue('insights');
    }

    public function handle(
        FacebookInsightDataService $evidenceService,
        InsightNarrativeGenerator $generator,
    ): void {
        /*
         * Claim the record before processing.
         *
         * Return an explicit decision so this job does not confuse
         * its own PROCESSING status with another worker's claim.
         */
        [$insight, $shouldProcess] = DB::transaction(
            function (): array {
                $record = PostAiInsight::query()
                    ->whereKey($this->insightId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Ordinary generation must not overwrite a completed
                 * insight. Explicit regeneration may do so.
                 */
                if (
                    ! $this->forceRegeneration
                    && $record->isCompleted()
                    && $record->generated_at !== null
                ) {
                    return [$record, false];
                }

                /*
                 * Do not allow a second ordinary job to claim a record
                 * that is already being processed.
                 */
                if (
                    ! $this->forceRegeneration
                    && $record->status === PostAiInsight::STATUS_PROCESSING
                ) {
                    return [$record, false];
                }

                $record->status = PostAiInsight::STATUS_PROCESSING;
                $record->error_message = null;
                $record->generation_attempts =
                    (int) $record->generation_attempts + 1;

                $record->save();

                return [$record, true];
            }
        );

        if (! $shouldProcess) {
            return;
        }

        try {
            $language = $insight->language;

            if (! in_array(
                $language,
                PostAiInsight::LANGUAGES,
                true,
            )) {
                throw new RuntimeException(
                    'Unsupported insight language.'
                );
            }

            /*
             * Retrieve the original generation parameters.
             */
            $request = data_get(
                $insight->metadata,
                'generation_request',
                [],
            );

            $dateFrom = $request['date_from'] ?? null;
            $dateTo = $request['date_to'] ?? null;

            /*
             * Use the selected model saved by queueGeneration().
             */
            $model = $insight->model_name
                ?: ($request['model'] ?? null)
                ?: config('services.degertu_ai.default_model');

            $allowedModels = config(
                'services.degertu_ai.allowed_models',
                [],
            );

            if (
                ! is_string($model)
                || trim($model) === ''
                || ! is_array($allowedModels)
                || ! in_array($model, $allowedModels, true)
            ) {
                throw new RuntimeException(
                    'The selected Degertu AI model is missing or not allowed.'
                );
            }

            /*
             * Collect evidence through the existing service.
             */
            $evidence = $evidenceService->collect(
                postId: $insight->facebook_post_id,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );

            if (! is_array($evidence) || $evidence === []) {
                throw new RuntimeException(
                    'No usable evidence was returned for this post.'
                );
            }

            $metrics = $evidence['metrics'] ?? [];

            if (! is_array($metrics)) {
                throw new RuntimeException(
                    'The evidence metrics are invalid.'
                );
            }

            $analyzedComments = $metrics['analyzed_comments'] ?? 0;

            if (
                ! is_numeric($analyzedComments)
                || (int) $analyzedComments < 1
            ) {
                throw new RuntimeException(
                    'No analyzed comments were found for this post and date range.'
                );
            }

            $evidenceHash = $this->hashEvidence($evidence);

            /*
             * Generate the insight using Degertu AI.
             */
            $result = $generator->generate(
                evidence: $evidence,
                language: $language,
                model: $model,
            );

            $this->validateResult($result);

            /*
             * Save the validated result atomically.
             *
             * Existing generated content is retained until this
             * transaction successfully writes the replacement.
             */
            DB::transaction(function () use (
                $result,
                $evidence,
                $metrics,
                $analyzedComments,
                $evidenceHash,
                $model,
            ): void {
                $record = PostAiInsight::query()
                    ->whereKey($this->insightId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $record->summary = trim($result['summary']);

                $record->key_findings = array_values(
                    $result['key_findings']
                );

                $record->recommendations = array_values(
                    $result['recommendations']
                );

                $record->model_name = $model;

                $record->model_version = config(
                    'services.degertu_ai.model_version'
                );

                $record->analysis_version = config(
                    'services.degertu_ai.analysis_version',
                    'v1',
                );

                $record->evidence_hash = $evidenceHash;
                $record->evidence_version = $evidenceHash;

                /*
                 * Reset optional evidence-derived fields to prevent
                 * stale values from a previous generation.
                 */
                $record->key_concern =
                    $evidence['key_concern'] ?? null;

                $record->primary_topic =
                    $evidence['primary_topic'] ?? null;

                $record->sentiment =
                    $evidence['sentiment'] ?? null;

                $record->priority =
                    $evidence['priority'] ?? null;

                $record->actionable = isset($evidence['actionable'])
                    ? (bool) $evidence['actionable']
                    : false;

                $record->analyzed_comments = max(
                    0,
                    (int) $analyzedComments,
                );

                /*
                 * Reset confidence before optionally assigning
                 * the new evidence-derived value.
                 */
                $record->confidence = null;

                if (
                    isset($metrics['confidence'])
                    && is_numeric($metrics['confidence'])
                ) {
                    $record->confidence = min(
                        1,
                        max(0, (float) $metrics['confidence']),
                    );
                }

                $record->status = PostAiInsight::STATUS_COMPLETED;
                $record->error_message = null;

                /*
                 * Update the timestamp only after successful generation.
                 */
                $record->generated_at = now();

                $record->save();
            });
        } catch (Throwable $exception) {
            report($exception);

            /*
             * Preserve existing generated content and let Laravel retry.
             */
            throw $exception;
        }
    }

    /**
     * Called when Laravel exhausts the configured attempts.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($exception): void {
                $record = PostAiInsight::query()
                    ->whereKey($this->insightId)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    return;
                }

                /*
                 * If a previous insight was completed successfully,
                 * preserve its content and timestamp after a failed
                 * regeneration.
                 */
                if (
                    $record->isCompleted()
                    && $record->generated_at !== null
                ) {
                    return;
                }

                $record->status = PostAiInsight::STATUS_FAILED;

                $record->error_message = Str::limit(
                    sprintf(
                        '%s: %s',
                        $exception
                            ? class_basename($exception)
                            : 'QueueFailure',
                        $exception
                            ? $exception->getMessage()
                            : 'The insight generation failed.',
                    ),
                    2000,
                );

                $record->save();
            });
        } catch (Throwable $failureException) {
            report($failureException);
        }
    }

    /**
     * Validate the complete AI response.
     */
    private function validateResult(array $result): void
    {
        if (
            ! isset($result['summary'])
            || ! is_string($result['summary'])
            || trim($result['summary']) === ''
        ) {
            throw new RuntimeException(
                'The AI response contains no valid summary.'
            );
        }

        foreach (['key_findings', 'recommendations'] as $field) {
            if (
                ! isset($result[$field])
                || ! is_array($result[$field])
            ) {
                throw new RuntimeException(
                    "The AI response contains an invalid {$field} field."
                );
            }

            foreach ($result[$field] as $item) {
                if (! is_string($item) || trim($item) === '') {
                    throw new RuntimeException(
                        "The AI response contains an invalid item in {$field}."
                    );
                }
            }
        }
    }

    /**
     * Create a stable SHA-256 hash of the evidence.
     *
     * @throws JsonException
     */
    private function hashEvidence(array $evidence): string
    {
        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonicalize($item);
                }
            }

            unset($item);

            return $value;
        };

        $json = json_encode(
            $canonicalize($evidence),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $json);
    }
}
