<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use App\Models\SentimentAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FacebookPostCommentInsightsService
{
    /**
     * Return the most-liked top-level comment for one sentiment.
     *
     * Only the latest persisted analysis for each comment is considered.
     * Replies are excluded. No Meta requests or AI inference are performed.
     *
     * @return array<string, mixed>|null
     */
    public function mostLikedComment(
        FacebookPost $post,
        string $sentiment,
        ?CarbonImmutable $startAt = null,
        ?CarbonImmutable $endExclusive = null,
    ): ?array {
        $latestAnalysisSql = <<<'SQL'
            SELECT sa_latest.sentiment
            FROM sentiment_analyses AS sa_latest
            WHERE sa_latest.facebook_comment_id = facebook_comments.id
            ORDER BY
                sa_latest.analyzed_at DESC NULLS LAST,
                sa_latest.created_at DESC,
                sa_latest.id DESC
            LIMIT 1
        SQL;

        $query = FacebookComment::query()
            ->where(
                'facebook_comments.facebook_post_id',
                $post->getKey(),
            )
            ->whereNull('facebook_comments.parent_comment_id')
            ->whereNotNull('facebook_comments.like_count')
            ->whereRaw(
                "LOWER(TRIM(($latestAnalysisSql)::text)) = ?",
                [strtolower($sentiment)],
            );

        if ($startAt !== null) {
            $query->where(
                'facebook_comments.commented_at',
                '>=',
                $startAt,
            );
        }

        if ($endExclusive !== null) {
            $query->where(
                'facebook_comments.commented_at',
                '<',
                $endExclusive,
            );
        }

        $comment = $query
            ->orderByDesc('facebook_comments.like_count')
            ->orderByRaw(
                'facebook_comments.commented_at DESC NULLS LAST',
            )
            ->orderByDesc('facebook_comments.created_at')
            ->orderByDesc('facebook_comments.id')
            ->first();

        if ($comment === null) {
            return null;
        }

        /*
         * Load the latest analysis explicitly for this comment.
         * This avoids relying on eager-loading behavior to select the
         * correct latest row across a collection of comments.
         */
        $analysis = SentimentAnalysis::query()
            ->where(
                'facebook_comment_id',
                $comment->getKey(),
            )
            ->orderByRaw('"analyzed_at" DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        // A concurrent delete or data change must not produce an
        // insight without the analysis that qualified the comment.
        if ($analysis === null) {
            return null;
        }

        return [
            'id' => $comment->getKey(),
            'facebook_comment_id' => $comment->facebook_comment_id,
            'author' => $comment->author_name,
            'message' => $comment->message,
            'permalink_url' => $comment->permalink_url,
            'commented_at' => $comment->commented_at?->toISOString(),
            'likes' => $comment->like_count,
            'sentiment' => strtolower(
                trim((string) $analysis->sentiment),
            ),
            'sentiment_confidence' => $analysis->confidence,
            'language' => $analysis->language,
        ];
    }

/**
 * Return sentiment overview, distribution, and daily timeline for a post.
 *
 * Only the latest valid sentiment analysis per comment is included.
 * The date range is applied to the comment's commented_at timestamp.
 *
 * When both date boundaries are null, all available dates are included.
 *
 * The categorical score uses:
 * - Negative: 1
 * - Neutral: 3
 * - Positive: 5
 *
 * @return array<string, mixed>
 */
public function sentimentOverview(
    FacebookPost $post,
    ?CarbonImmutable $startAt,
    ?CarbonImmutable $endExclusive,
): array {
    /*
     * Select the latest analysis for each comment.
     *
     * PostgreSQL DISTINCT ON selects one row per comment, using the
     * same ordering as the latestSentimentAnalysis relationship.
     */
    $latestAnalyses = DB::table('sentiment_analyses as sa')
        ->selectRaw(
            'DISTINCT ON (sa.facebook_comment_id)
                sa.id,
                sa.facebook_comment_id,
                sa.sentiment',
        )
        ->orderBy('sa.facebook_comment_id')
        ->orderByRaw('sa.analyzed_at DESC NULLS LAST')
        ->orderByDesc('sa.created_at')
        ->orderByDesc('sa.id');

    /*
     * Base query: one latest analysis per comment.
     *
     * Apply date filters only when boundaries are supplied.
     * Comments without a sentiment analysis are excluded by the
     * inner join, and unsupported sentiment labels are excluded.
     */
    $baseQuery = DB::table('facebook_comments as fc')
        ->joinSub(
            $latestAnalyses,
            'latest',
            'latest.facebook_comment_id',
            '=',
            'fc.id',
        )
        ->where('fc.facebook_post_id', $post->getKey())
        ->whereIn(
            DB::raw('LOWER(TRIM(latest.sentiment))'),
            ['positive', 'neutral', 'negative'],
        );

    if ($startAt !== null) {
        $baseQuery->where(
            'fc.commented_at',
            '>=',
            $startAt,
        );
    }

    if ($endExclusive !== null) {
        $baseQuery->where(
            'fc.commented_at',
            '<',
            $endExclusive,
        );
    }

    /*
     * Overall sentiment and distribution counts.
     */
    $summary = (clone $baseQuery)
        ->selectRaw("
            COUNT(*) AS analyzed_count,

            COUNT(*) FILTER (
                WHERE LOWER(TRIM(latest.sentiment)) = 'positive'
            ) AS positive_count,

            COUNT(*) FILTER (
                WHERE LOWER(TRIM(latest.sentiment)) = 'neutral'
            ) AS neutral_count,

            COUNT(*) FILTER (
                WHERE LOWER(TRIM(latest.sentiment)) = 'negative'
            ) AS negative_count,

            AVG(
                CASE LOWER(TRIM(latest.sentiment))
                    WHEN 'positive' THEN 5.0
                    WHEN 'neutral' THEN 3.0
                    WHEN 'negative' THEN 1.0
                END
            ) AS average_sentiment_score
        ")
        ->first();

    $analyzedCount = (int) ($summary->analyzed_count ?? 0);
    $positiveCount = (int) ($summary->positive_count ?? 0);
    $neutralCount = (int) ($summary->neutral_count ?? 0);
    $negativeCount = (int) ($summary->negative_count ?? 0);

    $averageScore = $summary->average_sentiment_score === null
        ? null
        : round((float) $summary->average_sentiment_score, 2);

    /*
     * Aggregate analyzed comments by calendar day.
     */
    $dailyRows = (clone $baseQuery)
        ->selectRaw("
            DATE(fc.commented_at) AS date,
            COUNT(*) AS comment_count,
            AVG(
                CASE LOWER(TRIM(latest.sentiment))
                    WHEN 'positive' THEN 5.0
                    WHEN 'neutral' THEN 3.0
                    WHEN 'negative' THEN 1.0
                END
            ) AS average_sentiment_score
        ")
        ->groupByRaw('DATE(fc.commented_at)')
        ->orderByRaw('DATE(fc.commented_at)')
        ->get()
        ->keyBy(
            fn (object $row): string => (string) $row->date,
        );

    /*
     * Build the timeline.
     *
     * For a bounded period, use the requested boundaries.
     * For All time, use the first and last dates containing analyzed
     * comments. This avoids generating a huge timeline from the
     * beginning of the Unix epoch or from unrelated dates.
     */
    $timeline = [];

    if ($dailyRows->isNotEmpty()) {
        $firstDate = $startAt !== null
            ? $startAt->startOfDay()
            : CarbonImmutable::parse(
                (string) $dailyRows->keys()->first(),
            )->startOfDay();

        $lastDate = $endExclusive !== null
            ? $endExclusive->subDay()->startOfDay()
            : CarbonImmutable::parse(
                (string) $dailyRows->keys()->last(),
            )->startOfDay();

        for (
            $date = $firstDate;
            $date->lte($lastDate);
            $date = $date->addDay()
        ) {
            $dateKey = $date->toDateString();
            $row = $dailyRows->get($dateKey);

            $timeline[] = [
                'date' => $dateKey,
                'comment_count' => (int) ($row->comment_count ?? 0),
                'average_sentiment_score' => $row === null
                    ? null
                    : round(
                        (float) $row->average_sentiment_score,
                        2,
                    ),
            ];
        }
    } elseif ($startAt !== null && $endExclusive !== null) {
        /*
         * A bounded period with no analyzed comments still returns
         * its requested dates, each with a zero count.
         *
         * All time with no analyzed comments returns an empty timeline.
         */
        $lastDate = $endExclusive->subDay()->startOfDay();

        for (
            $date = $startAt->startOfDay();
            $date->lte($lastDate);
            $date = $date->addDay()
        ) {
            $timeline[] = [
                'date' => $date->toDateString(),
                'comment_count' => 0,
                'average_sentiment_score' => null,
            ];
        }
    }

    /*
     * Return the API-ready overview.
     */
    return [
        'overall_sentiment' => [
            'score' => $averageScore,
            'scale' => [
                'min' => 1,
                'max' => 5,
            ],
            'label' => match (true) {
                $averageScore === null => 'No analysis yet',
                $averageScore >= 4.0 => 'Positive',
                $averageScore >= 2.5 => 'Neutral',
                default => 'Negative',
            },
            'analyzed_count' => $analyzedCount,
        ],

        'sentiment_distribution' => [
            'positive' => [
                'count' => $positiveCount,
                'percentage' => $analyzedCount > 0
                    ? round(
                        ($positiveCount / $analyzedCount) * 100,
                        2,
                    )
                    : 0,
            ],
            'neutral' => [
                'count' => $neutralCount,
                'percentage' => $analyzedCount > 0
                    ? round(
                        ($neutralCount / $analyzedCount) * 100,
                        2,
                    )
                    : 0,
            ],
            'negative' => [
                'count' => $negativeCount,
                'percentage' => $analyzedCount > 0
                    ? round(
                        ($negativeCount / $analyzedCount) * 100,
                        2,
                    )
                    : 0,
            ],
            'total' => $analyzedCount,
            'has_data' => $analyzedCount > 0,
        ],

        'sentiment_timeline' => [
            'has_data' => $analyzedCount > 0,
            'data' => $timeline,
        ],
    ];
}
}
