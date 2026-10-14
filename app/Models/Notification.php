<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'notifications';

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
        'title',
        'message',
        'type',
        'priority',
        'status',
        'audience_type',
        'published_at',
        'scheduled_at',
        'expires_at',
        'created_by',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Administrator/user who created the notification.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * Users who received this notification.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(
            NotificationRecipient::class,
            'notification_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /*
    |--------------------------------------------------------------------------
    | Priority Helpers
    |--------------------------------------------------------------------------
    */

    public function isNormal(): bool
    {
        return $this->priority === 'normal';
    }

    public function isImportant(): bool
    {
        return $this->priority === 'important';
    }

    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Helpers
    |--------------------------------------------------------------------------
    */

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function isScheduledForFuture(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_at !== null
            && $this->scheduled_at->isFuture();
    }

    public function isPublishable(): bool
    {
        return in_array(
            $this->status,
            ['draft', 'scheduled'],
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePublished($query)
    {
        return $query->where(
            'status',
            'published'
        );
    }

    public function scopeScheduled($query)
    {
        return $query->where(
            'status',
            'scheduled'
        );
    }

    public function scopeDraft($query)
    {
        return $query->where(
            'status',
            'draft'
        );
    }

    public function scopeArchived($query)
    {
        return $query->where(
            'status',
            'archived'
        );
    }

    public function scopeActive($query)
    {
        return $query
            ->where('status', 'published')
            ->where(function ($query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere(
                        'expires_at',
                        '>',
                        now()
                    );
            });
    }

    public function scopeScheduledForPublishing($query)
    {
        return $query
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where(
                'scheduled_at',
                '<=',
                now()
            );
    }
}