<?php

declare(strict_types=1);

namespace App\Modules\Insights\Services;

use App\Jobs\GeneratePostAiInsightJob;
use App\Models\PostAiInsight;
use App\Modules\Insights\Resources\FacebookPostInsightData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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

        [$dateFrom, $dateTo] = $this->normalizeDateRange(
            $dateFrom,
            $dateTo,
        );

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
     * Generation scope:
     * - Facebook post ID
     * - Language
     * - Exact normalized date range
     *
     * Existing completed results are reused unless regeneration
     * is explicitly requested.
     *
     * A matching request already in progress is never dispatched
     * a second time by this method.
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

        [$dateFrom, $dateTo] = $this->normalizeDateRange(
            $dateFrom,
            $dateTo,
        );

        $model = $this->resolveModel($requestedModel);

        /*
         * Lock an existing matching row during the transaction.
         *
         * For concurrent first-time requests, the database should
         * also enforce a unique generation-scope constraint.
         */
        [$insight, $shouldDispatch] = DB::transaction(
            function () use (
                $postId,
                $language,
                $dateFrom,
                $dateTo,
                $model,
                $forceRegeneration,
            ): array {
                $query = PostAiInsight::query()
                    ->where('facebook_post_id', $postId)
                    ->where('language', $language);

                $this->applyDateRangeIdentity(
                    query: $query,
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                );

                $record = $query
                    ->lockForUpdate()
                    ->first();

                /*
                 * Never enqueue duplicate work for an active
                 * generation, including forced requests.
                 */
                if ($record && $record->isProcessing()) {
                    return [$record, false];
                }

                /*
                 * Reuse completed content unless regeneration
                 * was explicitly requested.
                 */
                if (
                    $record
                    && $record->isCompleted()
                    && ! $forceRegeneration
                ) {
                    return [$record, false];
                }

                /*
                 * A pending record is assumed to represent queued
                 * work. A separate recovery process must detect
                 * pending records whose jobs were never enqueued.
                 */
                if (
                    $record
                    && $record->status === PostAiInsight::STATUS_PENDING
                    && ! $forceRegeneration
                ) {
                    return [$record, false];
                }

                /*
                 * Reuse the existing record for this exact scope
                 * or create a new record.
                 */
                if (! $record) {
                    $record = new PostAiInsight();

                    $record->facebook_post_id = $postId;
                    $record->language = $language;
                    $record->date_from = $dateFrom;
                    $record->date_to = $dateTo;
                }

                $metadata = is_array($record->metadata)
                    ? $record->metadata
                    : [];

                $metadata['generation_request'] = [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'model' => $model,
                    'requested_at' => now()->toIso8601String(),
                    'force_regeneration' => $forceRegeneration,
                ];

                $record->date_from = $dateFrom;
                $record->date_to = $dateTo;
                $record->metadata = $metadata;
                $record->model_name = $model;
                $record->status = PostAiInsight::STATUS_PENDING;
                $record->error_message = null;

                /*
                 * Preserve the previous generated content and
                 * generated_at timestamp until the new generation
                 * succeeds.
                 */
                $record->save();

                return [$record, true];
            },
            attempts: 3,
        );

        if (! $shouldDispatch) {
            return $insight;
        }

        /*
         * Dispatch only after the database transaction has committed.
         *
         * If dispatch fails, mark this request as failed so it does
         * not remain permanently pending. Log the failure and rethrow.
         */
        try {
            GeneratePostAiInsightJob::dispatch(
                $insight->id,
                $forceRegeneration,
            )->onQueue('insights');
        } catch (Throwable $exception) {
            Log::error(
                'Failed to dispatch Facebook post insight generation job.',
                [
                    'insight_id' => $insight->id,
                    'post_id' => $postId,
                    'language' => $language,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            /*
             * Do not overwrite a record that another worker has
             * already started processing.
             */
            PostAiInsight::query()
                ->whereKey($insight->id)
                ->where('status', PostAiInsight::STATUS_PENDING)
                ->update([
                    'status' => PostAiInsight::STATUS_FAILED,
                    'error_message' =>
                        'The generation job could not be queued.',
                    'updated_at' => now(),
                ]);

            throw new RuntimeException(
                'Unable to queue Facebook post insight generation.',
                previous: $exception,
            );
        }

        return $insight->fresh() ?? $insight;
    }

    /**
     * Retrieve the latest completed insight for a Facebook post.
     *
     * If both date boundaries are null, search across all date ranges.
     * If either boundary is supplied, match the exact normalized range,
     * including a null value for an unbounded boundary.
     */
    public function getLatestInsight(
        string $postId,
        ?string $language = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): ?PostAiInsight {
        if ($language !== null) {
            $this->validateLanguage($language);
        }

        [$dateFrom, $dateTo] = $this->normalizeDateRange(
            $dateFrom,
            $dateTo,
        );

        $query = PostAiInsight::query()
            ->where('facebook_post_id', $postId)
            ->where('status', PostAiInsight::STATUS_COMPLETED);

        if ($language !== null) {
            $query->where('language', $language);
        }

        /*
         * No dates means "latest across all ranges".
         * A supplied boundary activates exact range matching.
         */
        if ($dateFrom !== null || $dateTo !== null) {
            $this->applyDateRangeIdentity(
                query: $query,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );
        }

        return $query
            ->orderByDesc('generated_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Paginate saved insights for the AI Insights dashboard.
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
        if (! empty($filters['language'])) {
            $this->validateLanguage($filters['language']);
        }

        $perPage = max(1, min($perPage, 100));

        $query = PostAiInsight::query()
            ->with('facebookPost');

        if (! empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }

        if (! empty($filters['model'])) {
            $query->where('model_name', $filters['model']);
        }

        if (! empty($filters['post_id'])) {
            $query->where(
                'facebook_post_id',
                $filters['post_id'],
            );
        }

        /*
         * These dashboard filters intentionally refer to the
         * insight generation timestamp, not the comment date range.
         */
        if (! empty($filters['from'])) {
            $query->whereDate(
                'generated_at',
                '>=',
                $filters['from'],
            );
        }

        if (! empty($filters['to'])) {
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
     * Collect and validate database-backed evidence.
     *
     * This method does not perform AI inference.
     *
     * @return array<string, mixed>
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
     * Normalize and validate the date range.
     *
     * Null means that boundary is unbounded. Partial ranges are
     * supported, for example from=2026-10-01 and to=null.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeDateRange(
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $dateFrom = $dateFrom !== null && trim($dateFrom) !== ''
            ? trim($dateFrom)
            : null;

        $dateTo = $dateTo !== null && trim($dateTo) !== ''
            ? trim($dateTo)
            : null;

        foreach ([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ] as $field => $date) {
            if (
                $date !== null
                && (
                    ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                    || ! $this->isValidCalendarDate($date)
                )
            ) {
                throw ValidationException::withMessages([
                    $field === 'dateFrom'
                        ? 'date_range.from'
                        : 'date_range.to' => [
                            'The date must be a valid date in YYYY-MM-DD format.',
                        ],
                ]);
            }
        }

        if (
            $dateFrom !== null
            && $dateTo !== null
            && $dateTo < $dateFrom
        ) {
            throw ValidationException::withMessages([
                'date_range.to' => [
                    'The end date must be on or after the start date.',
                ],
            ]);
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * Check that a date is a real calendar date.
     */
    private function isValidCalendarDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
        );

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Apply exact generation-scope date identity conditions.
     *
     * Null means that the stored boundary must also be null.
     */
    private function applyDateRangeIdentity(
        Builder $query,
        ?string $dateFrom,
        ?string $dateTo,
    ): void {
        if ($dateFrom === null) {
            $query->whereNull('date_from');
        } else {
            $query->whereDate('date_from', $dateFrom);
        }

        if ($dateTo === null) {
            $query->whereNull('date_to');
        } else {
            $query->whereDate('date_to', $dateTo);
        }
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

        $allowedModels = array_values(array_filter(
            $allowedModels,
            static fn (mixed $model): bool =>
                is_string($model) && $model !== '',
        ));

        $model = is_string($requestedModel)
            && trim($requestedModel) !== ''
                ? trim($requestedModel)
                : $defaultModel;

        if (! is_string($model) || trim($model) === '') {
            throw new RuntimeException(
                'The default Degertu AI model is not configured.',
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