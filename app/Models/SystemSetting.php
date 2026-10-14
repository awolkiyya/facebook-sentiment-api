<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    */

    protected $table = 'system_settings';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        /*
        | General
        */
        'application_name',
        'municipality_name',
        'application_description',
        'support_email',
        'support_phone',

        /*
        | Facebook
        */
        'facebook_graph_version',
        'facebook_sync_enabled',
        'facebook_auto_sync',
        'facebook_sync_interval',
        'facebook_posts_enabled',
        'facebook_comments_enabled',
        'facebook_reactions_enabled',
        'facebook_insights_enabled',

        /*
        | AI & Analytics
        */
        'ai_enabled',
        'ai_service_url',
        'ai_batch_size',
        'ai_automatic_analysis',

        /*
        | Notifications
        */
        'email_notifications',
        'failed_sync_alerts',
        'ai_failure_alerts',
        'security_alerts',
        'notification_email',

        /*
        | Security
        */
        'password_min_length',
        'max_login_attempts',
        'lockout_minutes',
        'require_strong_password',
        'require_two_factor',

        /*
        | Reports
        */
        'report_pdf_enabled',
        'report_excel_enabled',
        'report_default_format',
        'report_logo_enabled',
        'report_footer',

        /*
        | Maintenance
        */
        'maintenance_mode',
        'maintenance_message',
        'cache_enabled',
        'scheduled_tasks_enabled',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            /*
            | Facebook
            */
            'facebook_sync_enabled' => 'boolean',
            'facebook_auto_sync' => 'boolean',
            'facebook_posts_enabled' => 'boolean',
            'facebook_comments_enabled' => 'boolean',
            'facebook_reactions_enabled' => 'boolean',
            'facebook_insights_enabled' => 'boolean',

            /*
            | AI
            */
            'ai_enabled' => 'boolean',
            'ai_batch_size' => 'integer',
            'ai_automatic_analysis' => 'boolean',

            /*
            | Notifications
            */
            'email_notifications' => 'boolean',
            'failed_sync_alerts' => 'boolean',
            'ai_failure_alerts' => 'boolean',
            'security_alerts' => 'boolean',

            /*
            | Security
            */
            'password_min_length' => 'integer',
            'max_login_attempts' => 'integer',
            'lockout_minutes' => 'integer',
            'require_strong_password' => 'boolean',
            'require_two_factor' => 'boolean',

            /*
            | Reports
            */
            'report_pdf_enabled' => 'boolean',
            'report_excel_enabled' => 'boolean',
            'report_logo_enabled' => 'boolean',

            /*
            | Maintenance
            */
            'maintenance_mode' => 'boolean',
            'cache_enabled' => 'boolean',
            'scheduled_tasks_enabled' => 'boolean',

            /*
            | Timestamps
            */
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Singleton Scope
    |--------------------------------------------------------------------------
    */

    /**
     * Always return the application settings record.
     *
     * The system is designed to have one settings record.
     */
    public function scopeSingleton(
        Builder $query
    ): Builder {
        return $query->whereKey(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get the singleton settings record.
     *
     * Creates the default record when it does not exist.
     */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'application_name' => 'Mayor Social Analytics',
                'municipality_name' => 'Adama City',
            ]
        );
    }
}
