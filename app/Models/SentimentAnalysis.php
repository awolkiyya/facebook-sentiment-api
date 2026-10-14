<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentimentAnalysis extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'sentiment_analyses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'facebook_comment_id',
        'sentiment_model_version_id',

        'language',

        'sentiment',
        'confidence',

        'positive_score',
        'neutral_score',
        'negative_score',

        'processing_time_ms',

        'analyzed_at',

        'raw_prediction',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => 'float',

        'positive_score' => 'float',
        'neutral_score' => 'float',
        'negative_score' => 'float',

        'processing_time_ms' => 'integer',

        'analyzed_at' => 'datetime',

        'raw_prediction' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the Facebook comment associated with this analysis.
     *
     * sentiment_analyses.facebook_comment_id references
     * facebook_comments.id.
     */
    public function facebookComment(): BelongsTo
    {
        return $this->belongsTo(
            FacebookComment::class,
            'facebook_comment_id',
            'id',
        );
    }

    /**
     * Get the sentiment model version used for this analysis.
     */
    public function sentimentModelVersion(): BelongsTo
    {
        return $this->belongsTo(
            SentimentModelVersion::class,
            'sentiment_model_version_id',
            'id',
        );
    }
}