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

    protected $table = 'sentiment_analyses';

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

    protected $casts = [
        'confidence' => 'float',
        'positive_score' => 'float',
        'neutral_score' => 'float',
        'negative_score' => 'float',
        'processing_time_ms' => 'integer',
        'analyzed_at' => 'datetime',
        'raw_prediction' => 'array',
    ];

    /**
     * Facebook comment associated with this analysis.
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
     * Model version used to generate this prediction.
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
