<?php

namespace App\Modules\SystemSetting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | General
            |--------------------------------------------------------------------------
            */

            'application_name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'municipality_name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'application_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'support_email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'support_phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            /*
            |--------------------------------------------------------------------------
            | Facebook
            |--------------------------------------------------------------------------
            */

            'facebook_graph_version' => [
                'sometimes',
                'required',
                'string',
                'regex:/^v\d+\.\d+$/',
                'max:20',
            ],

            'facebook_sync_enabled' => [
                'sometimes',
                'boolean',
            ],

            'facebook_auto_sync' => [
                'sometimes',
                'boolean',
            ],

            'facebook_sync_interval' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:1440',
            ],

            'facebook_posts_enabled' => [
                'sometimes',
                'boolean',
            ],

            'facebook_comments_enabled' => [
                'sometimes',
                'boolean',
            ],

            'facebook_reactions_enabled' => [
                'sometimes',
                'boolean',
            ],

            'facebook_insights_enabled' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | AI & Analytics
            |--------------------------------------------------------------------------
            */

            'ai_enabled' => [
                'sometimes',
                'boolean',
            ],

            'ai_service_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:500',
            ],

            'ai_batch_size' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:512',
            ],

            'ai_automatic_analysis' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            'email_notifications' => [
                'sometimes',
                'boolean',
            ],

            'failed_sync_alerts' => [
                'sometimes',
                'boolean',
            ],

            'ai_failure_alerts' => [
                'sometimes',
                'boolean',
            ],

            'security_alerts' => [
                'sometimes',
                'boolean',
            ],

            'notification_email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Security
            |--------------------------------------------------------------------------
            */

            'password_min_length' => [
                'sometimes',
                'required',
                'integer',
                'min:6',
                'max:72',
            ],

            'max_login_attempts' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:20',
            ],

            'lockout_minutes' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:1440',
            ],

            'require_strong_password' => [
                'sometimes',
                'boolean',
            ],

            'require_two_factor' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            'report_pdf_enabled' => [
                'sometimes',
                'boolean',
            ],

            'report_excel_enabled' => [
                'sometimes',
                'boolean',
            ],

            'report_default_format' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'pdf',
                    'excel',
                ]),
            ],

            'report_logo_enabled' => [
                'sometimes',
                'boolean',
            ],

            'report_footer' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Maintenance
            |--------------------------------------------------------------------------
            */

            'maintenance_mode' => [
                'sometimes',
                'boolean',
            ],

            'maintenance_message' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],

            'cache_enabled' => [
                'sometimes',
                'boolean',
            ],

            'scheduled_tasks_enabled' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Normalize incoming values.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('facebook_sync_interval')) {
            $this->merge([
                'facebook_sync_interval' => (string) $this->input(
                    'facebook_sync_interval'
                ),
            ]);
        }
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'facebook_graph_version.regex' =>
                'Facebook Graph API version must use the format v26.0.',

            'facebook_sync_interval.integer' =>
                'Facebook sync interval must be a valid number of minutes.',

            'facebook_sync_interval.min' =>
                'Facebook sync interval must be at least 1 minute.',

            'ai_batch_size.max' =>
                'AI batch size cannot be greater than 512.',

            'report_default_format.in' =>
                'The report format must be either pdf or excel.',
        ];
    }
}

