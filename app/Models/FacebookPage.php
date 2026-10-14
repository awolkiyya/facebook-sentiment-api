<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPage extends Model
{
    use HasFactory;
    use HasUuids;

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'facebook_pages';

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
        'connected_by_user_id',

        // Meta Page information
        'facebook_page_id',
        'name',
        'category',
        'category_list',
        'followers_count',

        // Authentication
        'access_token_encrypted',
        'page_access_token_expires_at',

        // Monitoring
        'is_active',
        'last_synced_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | The Facebook Page Access Token is encrypted automatically by Laravel
    | when written to the database and decrypted automatically when accessed
    | through the model.
    |
    */

    protected function casts(): array
    {
        return [
            /*
             * Laravel automatically encrypts/decrypts the Page Access Token.
             */
            'access_token_encrypted' => 'encrypted',

            /*
             * Facebook category list returned by Meta.
             */
            'category_list' => 'array',

            /*
             * Latest follower count returned by Meta.
             */
            'followers_count' => 'integer',

            /*
             * Facebook Page Access Token expiration timestamp.
             */
            'page_access_token_expires_at' => 'datetime',

            /*
             * Whether this Page is currently monitored.
             */
            'is_active' => 'boolean',

            /*
             * Last successful synchronization timestamp.
             */
            'last_synced_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The Laravel user who connected this Facebook Page.
     */
    public function connectedByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'connected_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to Pages currently being monitored.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to Pages that are not currently being monitored.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope Pages belonging to a specific Laravel user.
     */
    public function scopeForUser(
        Builder $query,
        string $userId
    ): Builder {
        return $query->where(
            'connected_by_user_id',
            $userId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether this Page is currently being monitored.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Determine whether the Page Access Token has expired.
     *
     * If Meta did not provide an expiration timestamp, the token is treated
     * as not locally expired. This does NOT guarantee that Meta considers
     * the token valid.
     */
    public function hasExpiredToken(): bool
    {
        if (!$this->page_access_token_expires_at) {
            return false;
        }

        return $this->page_access_token_expires_at->isPast();
    }

    /**
     * Determine whether the Page Access Token is still valid according
     * to the locally stored expiration timestamp.
     *
     * A null expiration timestamp means that the local system has no
     * expiration information available.
     */
    public function hasValidToken(): bool
    {
        return !$this->hasExpiredToken();
    }

    /**
     * Determine whether an expiration timestamp is available.
     */
    public function hasTokenExpiration(): bool
    {
        return $this->page_access_token_expires_at !== null;
    }
}
