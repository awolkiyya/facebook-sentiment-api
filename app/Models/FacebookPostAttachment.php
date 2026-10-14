<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookPostAttachment extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'facebook_post_attachments';


    /*
    |--------------------------------------------------------------------------
    | Primary Key
    |--------------------------------------------------------------------------
    */

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;


    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'facebook_post_id',
        'parent_attachment_id',

        'type',
        'media_type',

        'url',
        'media_url',
        'thumbnail_url',

        'target_id',
        'target_url',

        'title',
        'description',

        'width',
        'height',

        'position',

        'raw_payload',
    ];


    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
            'raw_payload' => 'array',
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The Facebook post this attachment belongs to.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(
            FacebookPost::class,
            'facebook_post_id'
        );
    }


    /**
     * Parent attachment.
     *
     * Used for nested Meta attachment structures such as albums.
     *
     * NULL parent_attachment_id means this is a root attachment.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_attachment_id'
        );
    }


    /**
     * Child attachments.
     *
     * Used for nested Meta attachment structures.
     *
     * Example:
     *
     * Album
     *   ├── Photo
     *   ├── Photo
     *   └── Photo
     */
    public function children(): HasMany
    {
        return $this->hasMany(
            self::class,
            'parent_attachment_id'
        )->orderBy('position');
    }


    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only root/top-level attachments.
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_attachment_id');
    }


    /**
     * Only nested/child attachments.
     */
    public function scopeChildren(Builder $query): Builder
    {
        return $query->whereNotNull('parent_attachment_id');
    }


    /**
     * Filter by normalized media type.
     *
     * Examples:
     *
     * image
     * video
     * album
     * link
     * unavailable
     * unknown
     */
    public function scopeMediaType(
        Builder $query,
        string $mediaType
    ): Builder {
        return $query->where('media_type', $mediaType);
    }


    /**
     * Filter by original Meta attachment type.
     *
     * Examples:
     *
     * photo
     * album
     * video_inline
     * video_direct_response
     * share
     */
    public function scopeType(
        Builder $query,
        string $type
    ): Builder {
        return $query->where('type', $type);
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this attachment has child attachments.
     *
     * Uses an existence query rather than loading all children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }


    /**
     * Determine whether this is a root/top-level attachment.
     */
    public function isTopLevel(): bool
    {
        return $this->parent_attachment_id === null;
    }


    /**
     * Determine whether this is a nested/child attachment.
     */
    public function isChild(): bool
    {
        return $this->parent_attachment_id !== null;
    }
}