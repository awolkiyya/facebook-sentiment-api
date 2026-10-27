<?php

namespace App\Modules\Insights\Services;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class FacebookInsightDataService
{
    private const CHUNK_SIZE = 500;

    private const SAMPLE_SIZE = 10;

    /**
     * Collect real Facebook post comment and sentiment evidence.
     *
     * The supplied post ID must identify the local FacebookPost
     * database record, not the external Meta post ID.
     *
     * Comments are filtered by commented_at, inclusively by date.
     * A comment is considered analyzed when its latest persisted
     * sentiment analysis exists.
     *
     * No mock data or AI inference is performed here.
     */
    public function collect(
        string $postId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $this->validateDateRange($dateFrom, $dateTo);

        $post = FacebookPost::query()
            ->find($postId);

        if (! $post) {
            throw ValidationException::withMessages([
                'postId' => [
                    'The specified Facebook post was not found.',
                ],
            ]);
        }

        $commentsQuery = $this->commentsQuery(
            postId: (string) $post->getKey(),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        $totalComments = (clone $commentsQuery)->count();

        $sentimentCounts = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'unknown' => 0,
        ];

        $topicCounts = [];
        $languageCounts = [];

        $analyzedComments = 0;

        /*
         * Process comments in chunks to avoid loading every comment
         * and its sentiment analysis into memory at once.
         */
        (clone $commentsQuery)
            ->with('latestSentimentAnalysis')
            ->chunk(
                self::CHUNK_SIZE,
                function ($comments) use (
                    &$sentimentCounts,
                    &$topicCounts,
                    &$languageCounts,
                    &$analyzedComments,
                ): void {
                    foreach ($comments as $comment) {
                        $analysis = $comment->latestSentimentAnalysis;

                        if (! $analysis) {
                            continue;
                        }

                        $analyzedComments++;

                        /*
                         * Count only labels that are actually stored.
                         * Unknown or missing labels remain visible
                         * rather than being silently assigned a class.
                         */
                        $sentiment = $this->normalizeLabel(
                            $analysis->sentiment,
                        );

                        if (
                            $sentiment !== null
                            && array_key_exists(
                                $sentiment,
                                $sentimentCounts,
                            )
                        ) {
                            $sentimentCounts[$sentiment]++;
                        } else {
                            $sentimentCounts['unknown']++;
                        }

                        $topic = $this->normalizeLabel(
                            $analysis->topic,
                        );

                        $topic = $topic ?? 'unclassified';

                        $topicCounts[$topic] =
                            ($topicCounts[$topic] ?? 0) + 1;

                        $language = $this->normalizeLabel(
                            $analysis->language,
                        );

                        $language = $language ?? 'unknown';

                        $languageCounts[$language] =
                            ($languageCounts[$language] ?? 0) + 1;
                    }
                },
            );

        /*
         * Percentages use analyzed comments as their denominator.
         * Missing sentiment labels are represented by "unknown".
         */
        $sentimentPercentages = $this->calculatePercentages(
            $sentimentCounts,
        );

        $topicDistribution = $this->calculatePercentages(
            $topicCounts,
        );

        $languageDistribution = $this->calculatePercentages(
            $languageCounts,
        );

        /*
         * Return actual comments that have a persisted analysis.
         * Samples are the most recently commented analyzed comments
         * within the requested date range.
         */
        $sampleComments = $this->getSampleComments(
            postId: (string) $post->getKey(),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        return [
            'post_id' => (string) $post->getKey(),

            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],

            'metrics' => [
                'total_comments' => $totalComments,
                'analyzed_comments' => $analyzedComments,
                'unanalysed_comments' => max(
                    0,
                    $totalComments - $analyzedComments,
                ),
                'sentiment_distribution' => $sentimentCounts,
                'sentiment_percentages' => $sentimentPercentages,
                'topic_distribution' => $topicDistribution,
                'language_distribution' => $languageDistribution,
            ],

            'sample_comments' => $sampleComments,
        ];
    }

    /**
     * Build a query for comments belonging to a local Facebook post.
     *
     * Date boundaries are inclusive. An omitted boundary is unbounded.
     */
    private function commentsQuery(
        string $postId,
        ?string $dateFrom,
        ?string $dateTo,
    ): Builder {
        $query = FacebookComment::query()
            ->where('facebook_post_id', $postId);

        if ($dateFrom !== null) {
            $query->whereDate('commented_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('commented_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Retrieve actual analyzed comment text for the evidence sample.
     */
    private function getSampleComments(
        string $postId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        return $this->commentsQuery(
            postId: $postId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        )
            ->whereHas('latestSentimentAnalysis')
            ->with('latestSentimentAnalysis')
            ->orderByDesc('commented_at')
            ->orderByDesc('id')
            ->limit(self::SAMPLE_SIZE)
            ->get()
            ->map(
                static fn (FacebookComment $comment): string =>
                    trim((string) $comment->message),
            )
            ->filter(
                static fn (string $message): bool =>
                    $message !== '',
            )
            ->values()
            ->all();
    }

    /**
     * Convert counts into percentages.
     *
     * Percentages are rounded to two decimal places.
     */
    private function calculatePercentages(array $counts): array
    {
        $total = array_sum($counts);

        $percentages = [];

        foreach ($counts as $label => $count) {
            $percentages[$label] = $total > 0
                ? round(($count / $total) * 100, 2)
                : 0.0;
        }

        return $percentages;
    }

    /**
     * Normalize persisted category labels.
     */
    private function normalizeLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return $value !== '' ? $value : null;
    }

    /**
     * Validate date syntax and ensure the range is chronological.
     *
     * The request validator should also validate these parameters.
     */
    private function validateDateRange(
        ?string $dateFrom,
        ?string $dateTo,
    ): void {
        foreach ([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ] as $field => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value,
            );

            $errors = \DateTimeImmutable::getLastErrors();

            if (
                $date === false
                || (
                    $errors !== false
                    && (
                        $errors['warning_count'] > 0
                        || $errors['error_count'] > 0
                    )
                )
                || $date->format('Y-m-d') !== $value
            ) {
                throw ValidationException::withMessages([
                    $field => [
                        'The date must use the YYYY-MM-DD format.',
                    ],
                ]);
            }
        }

        if (
            $dateFrom !== null
            && trim($dateFrom) !== ''
            && $dateTo !== null
            && trim($dateTo) !== ''
            && $dateFrom > $dateTo
        ) {
            throw ValidationException::withMessages([
                'dateTo' => [
                    'The end date must be on or after the start date.',
                ],
            ]);
        }
    }
}