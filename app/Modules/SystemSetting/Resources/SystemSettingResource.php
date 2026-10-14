<?php

namespace App\Modules\SystemSetting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | General
            |--------------------------------------------------------------------------
            */

            'general' => [
                'application_name' => $this->application_name,
                'municipality_name' => $this->municipality_name,
                'application_description' => $this->application_description,
                'support_email' => $this->support_email,
                'support_phone' => $this->support_phone,
            ],

            /*
            |--------------------------------------------------------------------------
            | Facebook
            |--------------------------------------------------------------------------
            */

            'facebook' => [
                'graph_version' => $this->facebook_graph_version,
                'sync_enabled' => $this->facebook_sync_enabled,
                'auto_sync' => $this->facebook_auto_sync,
                'sync_interval' => (int) $this->facebook_sync_interval,
                'posts_enabled' => $this->facebook_posts_enabled,
                'comments_enabled' => $this->facebook_comments_enabled,
                'reactions_enabled' => $this->facebook_reactions_enabled,
                'insights_enabled' => $this->facebook_insights_enabled,
            ],

            /*
            |--------------------------------------------------------------------------
            | AI & Analytics
            |--------------------------------------------------------------------------
            */

            'ai' => [
                'enabled' => $this->ai_enabled,
                'service_url' => $this->ai_service_url,
                'batch_size' => $this->ai_batch_size,
                'automatic_analysis' => $this->ai_automatic_analysis,
            ],

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            'notifications' => [
                'email_notifications' => $this->email_notifications,
                'failed_sync_alerts' => $this->failed_sync_alerts,
                'ai_failure_alerts' => $this->ai_failure_alerts,
                'security_alerts' => $this->security_alerts,
                'notification_email' => $this->notification_email,
            ],

            /*
            |--------------------------------------------------------------------------
            | Security
            |--------------------------------------------------------------------------
            */

            'security' => [
                'password_min_length' => $this->password_min_length,
                'max_login_attempts' => $this->max_login_attempts,
                'lockout_minutes' => $this->lockout_minutes,
                'require_strong_password' => $this->require_strong_password,
                'require_two_factor' => $this->require_two_factor,
            ],

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            'reports' => [
                'pdf_enabled' => $this->report_pdf_enabled,
                'excel_enabled' => $this->report_excel_enabled,
                'default_format' => $this->report_default_format,
                'logo_enabled' => $this->report_logo_enabled,
                'footer' => $this->report_footer,
            ],

            /*
            |--------------------------------------------------------------------------
            | Maintenance
            |--------------------------------------------------------------------------
            */

            'maintenance' => [
                'mode' => $this->maintenance_mode,
                'message' => $this->maintenance_message,
                'cache_enabled' => $this->cache_enabled,
                'scheduled_tasks_enabled' => $this->scheduled_tasks_enabled,
            ],

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' => [
                'id' => $this->id,
                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
            ],
        ];
    }
}
