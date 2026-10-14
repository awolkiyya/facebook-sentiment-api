<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookPost extends Model
{
    use HasUuids;

    protected $table = 'facebook_posts';

    protected $fillable = [
        'facebook_page_id',
        'facebook_post_id',
        'message',
        'permalink_url',
        'published_at',
        'like_count',
        'comment_count',
        'share_count',
        'raw_payload',
    ];

    protected $casts = [
        'published_at' => 'datetime',

        'raw_payload' => 'array',

        'like_count' => 'integer',
        'comment_count' => 'integer',
        'share_count' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Facebook Page that owns this post.
     *
     * facebook_posts.facebook_page_id
     *      ->
     * facebook_pages.id
     */
    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(
            FacebookPage::class,
            'facebook_page_id'
        );
    }

    /**
     * Root-level attachments for this post.
     *
     * Child attachments, such as photos inside an album,
     * are available through the attachment's "children"
     * relationship.
     *
     * Example:
     *
     * Post
     *   ├── Photo
     *   ├── Video
     *   └── Album
     *        ├── Photo
     *        ├── Photo
     *        └── Photo
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(
            FacebookPostAttachment::class,
            'facebook_post_id'
        )
            ->whereNull('parent_attachment_id')
            ->orderBy('position');
    }

    /**
     * All attachments belonging to this post.
     *
     * Includes both root attachments and nested
     * attachments inside albums/subattachments.
     */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(
            FacebookPostAttachment::class,
            'facebook_post_id'
        )
            ->orderBy('position');
    }

    /**
     * Comments belonging to this post.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(
            FacebookComment::class,
            'facebook_post_id'
        );
    }
}