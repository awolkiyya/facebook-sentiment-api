<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | UUID Configuration
    |--------------------------------------------------------------------------
    */

    public $incrementing = false;

    protected $keyType = 'string';

    /*
    |--------------------------------------------------------------------------
    | Spatie Permission
    |--------------------------------------------------------------------------
    |
    | This model uses the API guard for roles and permissions.
    |
    */

    protected string $guard_name = 'api';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /*
    |--------------------------------------------------------------------------
    | Hidden Attributes
    |--------------------------------------------------------------------------
    */

    protected $hidden = [
        'password',
        'remember_token',

        'failed_login_attempts',
        'last_failed_login_at',
        'locked_until',
        'lockout_count',

        /*
        |--------------------------------------------------------------------------
        | Legacy MFA Attributes
        |--------------------------------------------------------------------------
        |
        | Keep these only if these columns still exist in the users table.
        | If MFA has been fully moved to user_mfa_methods, these can be
        | removed from both $hidden and $casts after the old columns
        | are removed by migration.
        |
        */

        'mfa_enabled',
        'mfa_confirmed_at',

        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',

            'password' => 'hashed',

            'is_active' => 'boolean',

            'last_login_at' => 'datetime',

            'last_failed_login_at' => 'datetime',

            'locked_until' => 'datetime',

            'failed_login_attempts' => 'integer',

            'lockout_count' => 'integer',

            /*
            |--------------------------------------------------------------------------
            | Legacy MFA Attributes
            |--------------------------------------------------------------------------
            |
            | Remove these once MFA is completely managed through
            | user_mfa_methods.
            |
            */

            'mfa_enabled' => 'boolean',

            'mfa_confirmed_at' => 'datetime',

            'deleted_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Facebook Pages
    |--------------------------------------------------------------------------
    */

    public function facebookPages(): HasMany
    {
        return $this->hasMany(
            FacebookPage::class,
            'connected_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MFA Methods
    |--------------------------------------------------------------------------
    */

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(
            UserMfaMethod::class,
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MFA Recovery Codes
    |--------------------------------------------------------------------------
    */

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(
            UserMfaRecoveryCode::class,
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Recipients
    |--------------------------------------------------------------------------
    |
    | Represents the notification delivery/read-tracking records
    | associated with this user.
    |
    */

    public function notificationRecipients(): HasMany
    {
        return $this->hasMany(
            NotificationRecipient::class,
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Received Notifications
    |--------------------------------------------------------------------------
    |
    | Direct access to notifications received by this user.
    |
    | The notification_recipients pivot contains:
    | - id
    | - read_at
    | - created_at
    | - updated_at
    |
    */

    public function receivedNotifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Notification::class,
            'notification_recipients',
            'user_id',
            'notification_id'
        )
            ->withPivot([
                'id',
                'read_at',
            ])
            ->withTimestamps();
    }
}