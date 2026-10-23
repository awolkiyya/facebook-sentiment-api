<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class FacebookCommentQueryService
{
    private const MAX_PER_PAGE = 100;

    /**
     * Supported sort modes.
     *
     * @var list<string>
     */
    private const SORT_MODES = [
        'newest',
        'oldest',
        'most_liked',
        'most_replies',
        'highest_sentiment_confidence',
        'highest_priority',
    ];

    /**
     * Paginate top-level comments belonging to an authorized post.
     *
     * All filters are applied before pagination so that the returned
     * records and pagination totals reflect the requested filters.
     *
     * Replies are loaded separately when a user expands a thread.
     * Commenter counts include top-level comments and replies across
     * the entire post.
     *
     * This service reads locally persisted data only. It does not call
     * Meta or execute AI inference.
     *
     * Database assumptions to verify against your migrations:
     * - facebook_comments.message contains the comment text.
     * - facebook_comments.author_facebook_id identifies the author.
     * - sentiment_analyses.sentiment stores positive/neutral/negative.
     * - sentiment_analyses.language stores en/am/om.
     * - latestSentimentAnalysis resolves the latest analysis record.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateForPost(
        FacebookPost $post,
        array $filters,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);
        $page = max($page, 1);

        $query = FacebookComment::query()
            ->where(
                'facebook_comments.facebook_post_id',
                $post->getKey(),
            )
            ->whereNull('facebook_comments.parent_comment_id')
            ->with('latestSentimentAnalysis');

        $this->applySearchFilter(
            $query,
            $filters['search'] ?? null,
        );

        $this->applySentimentFilter(
            $query,
            $filters['sentiment'] ?? null,
        );

        $this->applyLanguageFilter(
            $query,
            $filters['language'] ?? null,
        );

        $this->applyAnalysisStatusFilter(
            $query,
            $filters['analysis_status'] ?? null,
        );

        $this->applyCommenterFilter(
            $query,
            $post,
            $filters['commenter'] ?? null,
        );

        $this->applyDateFilters(
            $query,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );

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
     * Search comment text and author name.
     *
     * PostgreSQL ILIKE provides case-insensitive matching.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applySearchFilter(
        Builder $query,
        mixed $search,
    ): void {
        if (! is_string($search)) {
            return;
        }

        $search = trim($search);

        if ($search === '') {
            return;
        }

        // Escape SQL LIKE wildcard characters so user input is treated
        // as a literal search term rather than a wildcard expression.
        $escapedSearch = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search,
        );

        $pattern = '%' . $escapedSearch . '%';

        $query->where(function (Builder $nestedQuery) use ($pattern): void {
            $nestedQuery
                ->whereRaw(
                    "facebook_comments.message ILIKE ? ESCAPE '\\'",
                    [$pattern],
                )
                ->orWhereRaw(
                    "facebook_comments.author_name ILIKE ? ESCAPE '\\'",
                    [$pattern],
                );
        });
    }

    /**
     * Filter by the sentiment of the latest analysis record.
     *
     * This uses a correlated subquery to avoid matching an older analysis
     * while returning a different, newer analysis in the resource.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applySentimentFilter(
        Builder $query,
        mixed $sentiment,
    ): void {
        if (! in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
            return;
        }

        $query->whereRaw(
            "(
                SELECT LOWER(TRIM(sa_filter.sentiment::text))
                FROM sentiment_analyses AS sa_filter
                WHERE sa_filter.facebook_comment_id = facebook_comments.id
                ORDER BY
                    sa_filter.analyzed_at DESC NULLS LAST,
                    sa_filter.created_at DESC,
                    sa_filter.id DESC
                LIMIT 1
            ) = ?",
            [$sentiment],
        );
    }

    /**
     * Filter by the language of the latest analysis record.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applyLanguageFilter(
        Builder $query,
        mixed $language,
    ): void {
        if (! in_array($language, ['en', 'am', 'om'], true)) {
            return;
        }

        $query->whereRaw(
            "(
                SELECT LOWER(TRIM(sa_filter.language::text))
                FROM sentiment_analyses AS sa_filter
                WHERE sa_filter.facebook_comment_id = facebook_comments.id
                ORDER BY
                    sa_filter.analyzed_at DESC NULLS LAST,
                    sa_filter.created_at DESC,
                    sa_filter.id DESC
                LIMIT 1
            ) = ?",
            [$language],
        );
    }

    /**
     * Filter according to whether a comment has a persisted analysis.
     *
     * A comment is considered completed when at least one analysis
     * exists. A comment is pending when no analysis exists.
     *
     * If your application stores an explicit processing status and
     * distinguishes failed analyses from pending ones, adapt this
     * method to use that status instead.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applyAnalysisStatusFilter(
        Builder $query,
        mixed $analysisStatus,
    ): void {
        if (! in_array($analysisStatus, ['pending', 'completed'], true)) {
            return;
        }

        $analysisExists = function ($subQuery): void {
            $subQuery
                ->selectRaw('1')
                ->from('sentiment_analyses as sa_status')
                ->whereColumn(
                    'sa_status.facebook_comment_id',
                    'facebook_comments.id',
                );
        };

        if ($analysisStatus === 'completed') {
            $query->whereExists($analysisExists);

            return;
        }

        $query->whereNotExists($analysisExists);
    }

    /**
     * Filter top-level comments by the author's total comment count
     * across the entire post, including replies.
     *
     * Authors without a Facebook ID cannot be classified reliably
     * and are excluded when a commenter filter is active.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applyCommenterFilter(
        Builder $query,
        FacebookPost $post,
        mixed $commenter,
    ): void {
        if (! in_array($commenter, ['repeat', 'single'], true)) {
            return;
        }

        $authorCounts = FacebookComment::query()
            ->select('facebook_comments.author_facebook_id')
            ->where(
                'facebook_comments.facebook_post_id',
                $post->getKey(),
            )
            ->whereNotNull('facebook_comments.author_facebook_id')
            ->groupBy('facebook_comments.author_facebook_id');

        if ($commenter === 'repeat') {
            $authorCounts->havingRaw('COUNT(*) >= ?', [2]);
        } else {
            $authorCounts->havingRaw('COUNT(*) = ?', [1]);
        }

        $query
            ->whereNotNull('facebook_comments.author_facebook_id')
            ->whereIn(
                'facebook_comments.author_facebook_id',
                $authorCounts,
            );
    }

    /**
     * Filter by the comment's timestamp, inclusive of the supplied dates.
     *
     * start_date and end_date are expected in YYYY-MM-DD format,
     * validated by ListFacebookCommentsRequest.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applyDateFilters(
        Builder $query,
        mixed $startDate,
        mixed $endDate,
    ): void {
        if (is_string($startDate) && $startDate !== '') {
            $query->whereDate(
                'facebook_comments.commented_at',
                '>=',
                $startDate,
            );
        }

        if (is_string($endDate) && $endDate !== '') {
            $query->whereDate(
                'facebook_comments.commented_at',
                '<=',
                $endDate,
            );
        }
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
        if (! in_array($sortBy, self::SORT_MODES, true)) {
            $sortBy = 'newest';
        }

        switch ($sortBy) {
            case 'oldest':
                $query
                    ->orderByRaw(
                        'facebook_comments.commented_at ASC NULLS LAST',
                    )
                    ->orderBy('facebook_comments.created_at')
                    ->orderBy('facebook_comments.id');

                return;

            case 'most_liked':
                $query
                    ->orderByRaw(
                        'facebook_comments.like_count DESC NULLS LAST',
                    )
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                return;

            case 'most_replies':
                $query
                    ->withCount('replies')
                    ->orderByDesc('replies_count')
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.id');

                return;

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

                return;

            case 'highest_priority':
                $query
                    ->orderByRaw(
                        '(
                            SELECT
                                CASE LOWER(TRIM(sa_sort.priority::text))
                                    WHEN \'critical\' THEN 5
                                    WHEN \'urgent\' THEN 5
                                    WHEN \'high\' THEN 4
                                    WHEN \'medium\' THEN 3
                                    WHEN \'normal\' THEN 3
                                    WHEN \'low\' THEN 2
                                    WHEN \'info\' THEN 1
                                    WHEN \'informational\' THEN 1
                                    ELSE NULL
                                END
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

                return;

            case 'newest':
            default:
                $query
                    ->orderByRaw(
                        'facebook_comments.commented_at DESC NULLS LAST',
                    )
                    ->orderByDesc('facebook_comments.created_at')
                    ->orderByDesc('facebook_comments.id');

                return;
        }
    }
}
