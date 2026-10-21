<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class FacebookCommentQueryService
{
    private const MAX_PER_PAGE = 100;

    /**
     * Retrieve all locally stored comments belonging to an authorized post.
     *
     * No search, sentiment, language, analysis-status, or date filters
     * are applied. Pagination and sorting are retained.
     *
     * This method does not call Meta or execute AI inference.
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
            ->with('latestSentimentAnalysis');

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
     * Apply deterministic PostgreSQL sorting before pagination.
     *
     * @param Builder<FacebookComment> $query
     */
    private function applySorting(
        Builder $query,
        string $sortBy,
    ): void {
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
