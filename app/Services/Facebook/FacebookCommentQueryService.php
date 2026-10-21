<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class FacebookCommentQueryService
{
    /**
     * Maximum page size accepted by this service.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * SQL expression resolving the latest persisted analysis for a comment.
     *
     * Keep the ordering consistent with FacebookComment::latestSentimentAnalysis().
     */
    private const LATEST_ANALYSIS_ID_SQL = <<<'SQL'
        (
            SELECT sa_latest.id
            FROM sentiment_analyses AS sa_latest
            WHERE sa_latest.facebook_comment_id = facebook_comments.id
            ORDER BY
                sa_latest.analyzed_at DESC NULLS LAST,
                sa_latest.created_at DESC,
                sa_latest.id DESC
            LIMIT 1
        )
        SQL;

    /**
     * Supported comment sorting options.
     *
     * @var list<string>
     */
    private const SORT_OPTIONS = [
        'newest',
        'oldest',
        'most_liked',
        'most_replies',
        'highest_sentiment_confidence',
        'highest_priority',
    ];

    /**
     * Retrieve comments for an already-authorized Facebook post.
     *
     * This method queries local database records only. It does not call
     * Meta, dispatch analysis jobs, or execute AI inference.
     *
     * Supported filters:
     * - search
     * - sentiment
     * - language
     * - analysis_status: completed|pending
     * - start_date
     * - end_date
     *
     * Supported sorting:
     * - newest
     * - oldest
     * - most_liked
     * - most_replies
     * - highest_sentiment_confidence
     * - highest_priority
     *
     * The supplied post must already have been authorized by the caller.
     *
     * @param array<string, mixed> $filters Validated request filters.
     */
    public function paginateForPost(
        FacebookPost $post,
        array $filters,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $perPage = min(
            max($perPage, 1),
            self::MAX_PER_PAGE,
        );

        $page = max($page, 1);

        $query = FacebookComment::query()
            ->where(
                'facebook_comments.facebook_post_id',
                $post->getKey(),
            )
            ->with('latestSentimentAnalysis');

        $this->applySearch($query, $filters);
        $this->applyAnalysisFilters($query, $filters);
        $this->applyDateRange($query, $filters);
        $this->applySorting(
            $query,
            (string) ($filters['sort_by'] ?? 'newest'),
        );

        return $query
            ->paginate(
                perPage: $perPage,
                page: $page,
            )
            ->withQueryString();
    }

    /**
     * Apply a case-insensitive message search.
     *
     * @param Builder<FacebookComment> $query
     * @param array<string, mixed> $filters
     */
    private function applySearch(
        Builder $query,
        array $filters,
    ): void {
        $search = trim(
            (string) ($filters['search'] ?? ''),
        );

        if ($search === '') {
            return;
        }

        $query->where(
            'facebook_comments.message',
            'ILIKE',
            '%' . $search . '%',
        );
    }

    /**
     * Apply latest-analysis sentiment/language filters and analysis existence.
     *
     * @param Builder<FacebookComment> $query
     * @param array<string, mixed> $filters
     */
    private function applyAnalysisFilters(
        Builder $query,
        array $filters,
    ): void {
        $sentiment = $filters['sentiment'] ?? null;
        $language = $filters['language'] ?? null;
        $analysisStatus = $filters['analysis_status'] ?? null;

        if ($sentiment !== null && $sentiment !== '') {
            $this->whereLatestAnalysisMatches(
                $query,
                'sentiment',
                (string) $sentiment,
            );
        }

        if ($language !== null && $language !== '') {
            $this->whereLatestAnalysisMatches(
                $query,
                'language',
                (string) $language,
            );
        }

        if ($analysisStatus === 'completed') {
            $query->whereExists(
                static function ($analysisQuery): void {
                    $analysisQuery
                        ->selectRaw('1')
                        ->from('sentiment_analyses as sa')
                        ->whereColumn(
                            'sa.facebook_comment_id',
                            'facebook_comments.id',
                        );
                },
            );
        } elseif ($analysisStatus === 'pending') {
            $query->whereNotExists(
                static function ($analysisQuery): void {
                    $analysisQuery
                        ->selectRaw('1')
                        ->from('sentiment_analyses as sa')
                        ->whereColumn(
                            'sa.facebook_comment_id',
                            'facebook_comments.id',
                        );
                },
            );
        }
    }

    /**
     * Restrict results to comments whose latest analysis matches a filter.
     *
     * Column names are selected internally, never from user input.
     *
     * @param Builder<FacebookComment> $query
     */
    private function whereLatestAnalysisMatches(
        Builder $query,
        string $column,
        string $value,
    ): void {
        if (! in_array($column, ['sentiment', 'language'], true)) {
            throw new InvalidArgumentException(
                'Unsupported sentiment analysis filter column.',
            );
        }

        $query->whereExists(
            static function ($analysisQuery) use (
                $column,
                $value,
            ): void {
                $analysisQuery
                    ->selectRaw('1')
                    ->from('sentiment_analyses as sa')
                    ->whereColumn(
                        'sa.facebook_comment_id',
                        'facebook_comments.id',
                    )
                    ->whereRaw(
                        'sa.id = ' . self::LATEST_ANALYSIS_ID_SQL,
                    )
                    ->where('sa.' . $column, $value);
            },
        );
    }

    /**
     * Apply inclusive calendar-date boundaries to commented_at.
     *
     * The end date is converted to an exclusive next-day boundary so that
     * timestamps throughout the selected end date are included.
     *
     * @param Builder<FacebookComment> $query
     * @param array<string, mixed> $filters
     */
    private function applyDateRange(
        Builder $query,
        array $filters,
    ): void {
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        if ($startDate !== null && $startDate !== '') {
            $start = $this->parseDate((string) $startDate);

            $query->where(
                'facebook_comments.commented_at',
                '>=',
                $start,
            );
        }

        if ($endDate !== null && $endDate !== '') {
            $endExclusive = $this->parseDate(
                (string) $endDate,
            )->addDay();

            $query->where(
                'facebook_comments.commented_at',
                '<',
                $endExclusive,
            );
        }
    }

    /**
     * Parse a validated YYYY-MM-DD date.
     */
    private function parseDate(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $date,
        );

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(
                'Invalid date supplied to the comment query service.',
            );
        }

        return $parsed;
    }

    /**
     * Apply deterministic PostgreSQL sorting before pagination.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applySorting(
        Builder $query,
        string $sortBy,
    ): void {
        if (! in_array($sortBy, self::SORT_OPTIONS, true)) {
            throw new InvalidArgumentException(
                'Unsupported Facebook comment sort option.',
            );
        }

        switch ($sortBy) {
            case 'oldest':
                $query
                    ->orderByRaw(
                        'facebook_comments.commented_at ASC NULLS LAST',
                    )
                    ->orderBy('facebook_comments.created_at')
                    ->orderBy('facebook_comments.id');

                break;

            case 'most_liked':
                $query
                    ->orderByRaw(
                        'facebook_comments.like_count DESC NULLS LAST',
                    )
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                break;

            case 'most_replies':
                $query
                    ->withCount('replies')
                    ->orderByDesc('replies_count')
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                break;

            case 'highest_sentiment_confidence':
                $query
                    ->orderByRaw(
                        '(
                            SELECT sa_sort.confidence
                            FROM sentiment_analyses AS sa_sort
                            WHERE sa_sort.facebook_comment_id =
                                facebook_comments.id
                            ORDER BY
                                sa_sort.analyzed_at DESC NULLS LAST,
                                sa_sort.created_at DESC,
                                sa_sort.id DESC
                            LIMIT 1
                        ) DESC NULLS LAST',
                    )
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                break;

            case 'highest_priority':
                $priorityRankingSql = $this->priorityRankingSql(
                    'sa_sort.priority',
                );

                $query
                    ->orderByRaw(
                        '(
                            SELECT ' . $priorityRankingSql . '
                            FROM sentiment_analyses AS sa_sort
                            WHERE sa_sort.facebook_comment_id =
                                facebook_comments.id
                            ORDER BY
                                sa_sort.analyzed_at DESC NULLS LAST,
                                sa_sort.created_at DESC,
                                sa_sort.id DESC
                            LIMIT 1
                        ) DESC NULLS LAST',
                    )
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                break;

            case 'newest':
                $query
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.created_at')
                    ->orderByDesc('facebook_comments.id');

                break;
        }
    }

    /**
     * Translate persisted priority labels into numeric ranking values.
     *
     * Higher values sort first. Unrecognized and null priorities sort last.
     *
     * IMPORTANT:
     * Align this mapping with the actual values produced by your AI service.
     */
    private function priorityRankingSql(string $column): string
    {
        if ($column !== 'sa_sort.priority') {
            throw new InvalidArgumentException(
                'Unsupported priority ranking column.',
            );
        }

        return <<<'SQL'
            CASE LOWER(TRIM(sa_sort.priority::text))
                WHEN 'critical' THEN 5
                WHEN 'urgent' THEN 5
                WHEN 'high' THEN 4
                WHEN 'medium' THEN 3
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 2
                WHEN 'info' THEN 1
                WHEN 'informational' THEN 1
                ELSE NULL
            END
            SQL;
    }
}
