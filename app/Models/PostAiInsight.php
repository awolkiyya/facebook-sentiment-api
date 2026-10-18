<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostAiInsight extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table Configuration
    |--------------------------------------------------------------------------
    */

    protected $table = 'post_ai_insights';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
    |--------------------------------------------------------------------------
    | Generation Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    */

    public const LANGUAGE_ENGLISH = 'en';

    public const LANGUAGE_AMHARIC = 'am';

    public const LANGUAGE_AFAAN_OROMO = 'or';

    public const LANGUAGES = [
        self::LANGUAGE_ENGLISH,
        self::LANGUAGE_AMHARIC,
        self::LANGUAGE_AFAAN_OROMO,
    ];

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    |
    | Assign fields explicitly through validated application services.
    | Do not pass unvalidated request data directly into create() or update().
    |
    */

    protected $fillable = [
        'facebook_post_id',
        'language',
        'summary',
        'key_concern',
        'key_findings',
        'recommendations',
        'primary_topic',
        'sentiment',
        'priority',
        'actionable',
        'analyzed_comments',
        'confidence',
        'model_name',
        'model_version',
        'analysis_version',
        'status',
        'evidence_hash',
        'evidence_version',
        'error_message',
        'generation_attempts',
        'metadata',
        'generated_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'key_findings' => 'array',
            'recommendations' => 'array',
            'metadata' => 'array',
            'actionable' => 'boolean',
            'analyzed_comments' => 'integer',
            'generation_attempts' => 'integer',
            'confidence' => 'decimal:4',
            'generated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    |
    | This assumes your existing Facebook post model is FacebookPost
    | and is located at App\Models\FacebookPost.
    |
    */

    public function facebookPost(): BelongsTo
    {
        return $this->belongsTo(
            FacebookPost::class,
            'facebook_post_id',
            'id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForPost(
        Builder $query,
        string $postId
    ): Builder {
        return $query->where('facebook_post_id', $postId);
    }

    public function scopeForLanguage(
        Builder $query,
        string $language
    ): Builder {
        return $query->where('language', $language);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasSuccessfulResult(): bool
    {
        return $this->isCompleted()
            && $this->generated_at !== null
            && filled($this->summary);
    }

    public function supportsLanguage(string $language): bool
    {
        return in_array(
            $language,
            self::LANGUAGES,
            true
        );
    }
}
