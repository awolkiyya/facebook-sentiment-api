<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FacebookComment extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'facebook_comments';

    protected $fillable = [
        'facebook_post_id',
        'facebook_comment_id',
        'parent_comment_id',
        'author_name',
        'author_facebook_id',
        'message',
        'permalink_url',
        'commented_at',
        'like_count',
        'raw_payload',
    ];

    protected $casts = [
        'commented_at' => 'datetime',
        'like_count' => 'integer',
        'raw_payload' => 'array',
    ];

    /**
     * Facebook post this comment belongs to.
     */
    public function facebookPost(): BelongsTo
    {
        return $this->belongsTo(
            FacebookPost::class,
            'facebook_post_id',
            'id',
        );
    }

    /**
     * Parent comment, identified by the external Meta comment ID.
     */
    public function parentComment(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_comment_id',
            'facebook_comment_id',
        );
    }

    /**
     * Replies belonging to this comment.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(
            self::class,
            'parent_comment_id',
            'facebook_comment_id',
        );
    }

    /**
     * All persisted sentiment analyses for this comment.
     */
    public function sentimentAnalyses(): HasMany
    {
        return $this->hasMany(
            SentimentAnalysis::class,
            'facebook_comment_id',
            'id',
        );
    }

    /**
     * Latest persisted sentiment analysis.
     *
     * Uses ordered HasOne selection rather than ofMany(),
     * avoiding MAX() aggregation on UUID primary keys in PostgreSQL.
     *
     * Ordering:
     * 1. Latest analyzed_at, with NULL timestamps last.
     * 2. Latest created_at.
     * 3. UUID descending as a deterministic tie-breaker.
     */
    public function latestSentimentAnalysis(): HasOne
    {
        return $this->hasOne(
            SentimentAnalysis::class,
            'facebook_comment_id',
            'id',
        )
            ->orderByRaw('"analyzed_at" DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
