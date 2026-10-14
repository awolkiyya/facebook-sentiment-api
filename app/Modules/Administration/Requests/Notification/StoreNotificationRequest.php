<?php

namespace App\Modules\Administration\Requests\Notification;

use App\Services\NotificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            */

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'message' => [
                'required',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | Classification
            |--------------------------------------------------------------------------
            */

            'type' => [
                'nullable',
                'string',
                Rule::in([
                    NotificationService::TYPE_ANNOUNCEMENT,
                    NotificationService::TYPE_MAINTENANCE,
                    NotificationService::TYPE_SECURITY,
                    NotificationService::TYPE_POLICY,
                    NotificationService::TYPE_SYSTEM,
                ]),
            ],

            'priority' => [
                'nullable',
                'string',
                Rule::in([
                    NotificationService::PRIORITY_NORMAL,
                    NotificationService::PRIORITY_IMPORTANT,
                    NotificationService::PRIORITY_URGENT,
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Audience
            |--------------------------------------------------------------------------
            */

            'audience_type' => [
                'required',
                'string',
                Rule::in([
                    NotificationService::AUDIENCE_ALL_USERS,
                    NotificationService::AUDIENCE_ROLE,
                    NotificationService::AUDIENCE_SELECTED_USERS,
                ]),
            ],

            'audience_data' => [
                'nullable',
                'array',
            ],

            /*
            |--------------------------------------------------------------------------
            | Role Audience
            |--------------------------------------------------------------------------
            */

            'audience_data.roles' => [
                'nullable',
                'array',
            ],

            'audience_data.roles.*' => [
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Selected User Audience
            |--------------------------------------------------------------------------
            */

            'audience_data.user_ids' => [
                'nullable',
                'array',
            ],

            'audience_data.user_ids.*' => [
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Expiration
            |--------------------------------------------------------------------------
            */

            'expires_at' => [
                'nullable',
                'date',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'The notification title is required.',

            'title.max' => 'The notification title may not exceed 255 characters.',

            'message.required' => 'The notification message is required.',

            'audience_type.required' => 'The audience type is required.',

            'audience_data.array' => 'Audience data must be a valid object.',

            'audience_data.roles.array' => 'Roles must be provided as an array.',

            'audience_data.user_ids.array' => 'User IDs must be provided as an array.',

            'audience_data.user_ids.*.uuid' => 'Each selected user ID must be a valid UUID.',

            'expires_at.date' => 'The expiration date must be a valid date.',
        ];
    }
}