<?php

namespace App\Modules\Administration\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleNotificationRequest extends FormRequest
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
            'scheduled_at' => [
                'required',
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
            'scheduled_at.required' => 'The scheduled date and time is required.',

            'scheduled_at.date' => 'The scheduled date and time must be valid.',
        ];
    }
}