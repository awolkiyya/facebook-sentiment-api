<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookComment;
use App\Models\FacebookPost;
use App\Models\SentimentAnalysis;
use Carbon\CarbonImmutable;

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
}
