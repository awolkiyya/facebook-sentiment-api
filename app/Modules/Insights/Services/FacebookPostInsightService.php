<?php

namespace App\Modules\Insights\Services;

use App\Jobs\GeneratePostAiInsightJob;
use App\Models\PostAiInsight;
use App\Modules\Insights\Resources\FacebookPostInsightData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FacebookPostInsightService
{
    public function __construct(
        private readonly FacebookInsightDataService $dataService,
        private readonly InsightNarrativeGenerator $narrativeGenerator,
    ) {}

    /**
     * Synchronous generation for existing callers.
     */
    public function generate(
        string $postId,
        string $language,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $requestedModel = null,
    ): FacebookPostInsightData {
        $this->validateLanguage($language);

        $model = $this->resolveModel($requestedModel);

        $evidence = $this->collectAndValidateEvidence(
            postId: $postId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        $narrative = $this->narrativeGenerator->generate(
            evidence: $evidence,
            language: $language,
            model: $model,
        );

        return new FacebookPostInsightData(
            postId: $postId,
            language: $language,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            metrics: $evidence['metrics'],
            summary: $narrative['summary'],
            keyFindings: $narrative['key_findings'],
            recommendations: $narrative['recommendations'],
            generatedAt: now()->toIso8601String(),
        );
    }

    /**
     * Queue insight generation for background processing.
     *
     * Reuses the existing record for the same post and language.
     * Completed insights are preserved unless regeneration is explicitly requested.
     */
    public function queueGeneration(
        string $postId,
        string $language,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $requestedModel = null,
        bool $forceRegeneration = false,
    ): PostAiInsight {
        $this->validateLanguage($language);

        $model = $this->resolveModel($requestedModel);

        [$insight, $shouldDispatch] = DB::transaction(function () use (
            $postId,
            $language,
            $dateFrom,
            $dateTo,
            $model,
            $forceRegeneration,
        ): array {
            $record = PostAiInsight::query()
                ->where('facebook_post_id', $postId)
                ->where('language', $language)
                ->lockForUpdate()
                ->first();

            // Never queue a second job while generation is already running.
            if ($record && $record->isProcessing()) {
                return [$record, false];
            }

            // Preserve completed results unless regeneration was explicitly requested.
            if (
                $record
                && $record->isCompleted()
                && ! $forceRegeneration
            ) {
                return [$record, false];
            }

            // Reuse the existing record or create a new one.
            if (! $record) {
                $record = new PostAiInsight();
                $record->facebook_post_id = $postId;
                $record->language = $language;
            }

            // An existing pending request should not create duplicate jobs
            // unless regeneration was explicitly requested.
            if (
                $record->status === PostAiInsight::STATUS_PENDING
                && ! $forceRegeneration
            ) {
                return [$record, false];
            }

            $metadata = is_array($record->metadata)
                ? $record->metadata
                : [];

            $metadata['generation_request'] = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'model' => $model,
            ];

            $record->metadata = $metadata;
            $record->model_name = $model;
            $record->status = PostAiInsight::STATUS_PENDING;
            $record->error_message = null;

            // Keep previously generated content and generated_at until
            // the replacement insight has been generated successfully.
            $record->save();

            return [$record, true];
        });

        if ($shouldDispatch) {
            GeneratePostAiInsightJob::dispatch(
                $insight->id,
                $forceRegeneration,
            )->onQueue('insights');
        }

        return $insight;
    }

    /**
     * Retrieve the latest completed insight for one Facebook post.
     *
     * When language is null, return the most recently generated
     * completed insight in any supported language.
     */
    public function getLatestInsight(
        string $postId,
        ?string $language = null,
    ): ?PostAiInsight {
        if ($language !== null) {
            $this->validateLanguage($language);
        }

        $query = PostAiInsight::query()
            ->where('facebook_post_id', $postId)
            ->where('status', PostAiInsight::STATUS_COMPLETED);

        if ($language !== null) {
            $query->where('language', $language);
        }

        return $query
            ->orderByDesc('generated_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    
    
/**
 * Paginate saved insights for the AI Insights page.
 *
 * Supported filters:
 * - language
 * - model
 * - post_id
 * - from
 * - to
 */
public function paginateInsights(
    array $filters = [],
    int $perPage = 15,
): LengthAwarePaginator {
    if (!empty($filters['language'])) {
        $this->validateLanguage($filters['language']);
    }

    $perPage = max(1, min($perPage, 100));

    $query = PostAiInsight::query()
        ->with('facebookPost');

    if (!empty($filters['language'])) {
        $query->where('language', $filters['language']);
    }

    if (!empty($filters['model'])) {
        $query->where('model_name', $filters['model']);
    }

    if (!empty($filters['post_id'])) {
        $query->where(
            'facebook_post_id',
            $filters['post_id'],
        );
    }

    if (!empty($filters['from'])) {
        $query->whereDate(
            'generated_at',
            '>=',
            $filters['from'],
        );
    }

    if (!empty($filters['to'])) {
        $query->whereDate(
            'generated_at',
            '<=',
            $filters['to'],
        );
    }

    return $query
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->paginate($perPage)
        ->withQueryString();
}


    /**
     * Collect and validate evidence for generation.
     */
    private function collectAndValidateEvidence(
        string $postId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $evidence = $this->dataService->collect(
            postId: $postId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        $metrics = $evidence['metrics'] ?? [];
        $analyzedComments = $metrics['analyzed_comments'] ?? 0;

        if (
            ! is_numeric($analyzedComments)
            || (int) $analyzedComments < 1
        ) {
            throw ValidationException::withMessages([
                'postId' => [
                    'No analyzed comments were found for this post and date range.',
                ],
            ]);
        }

        return $evidence;
    }

    /**
     * Validate the requested language.
     */
    private function validateLanguage(string $language): void
    {
        if (! in_array(
            $language,
            PostAiInsight::LANGUAGES,
            true,
        )) {
            throw ValidationException::withMessages([
                'language' => [
                    'Unsupported insight language.',
                ],
            ]);
        }
    }

    /**
     * Resolve and validate the requested AI model.
     */
    private function resolveModel(?string $requestedModel): string
    {
        $allowedModels = config(
            'services.degertu_ai.allowed_models',
            [],
        );

        $defaultModel = config(
            'services.degertu_ai.default_model',
        );

        if (! is_array($allowedModels)) {
            $allowedModels = [];
        }

        $model = is_string($requestedModel)
            && trim($requestedModel) !== ''
                ? trim($requestedModel)
                : $defaultModel;

        if (! is_string($model) || trim($model) === '') {
            throw new RuntimeException(
                'The default Degertu AI model is not configured.'
            );
        }

        if (! in_array($model, $allowedModels, true)) {
            throw ValidationException::withMessages([
                'model' => [
                    'The selected Degertu AI model is not allowed.',
                ],
            ]);
        }

        return $model;
    }
}
