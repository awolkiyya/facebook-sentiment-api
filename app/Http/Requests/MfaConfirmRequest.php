<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MfaConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'regex:/^\d{6}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'The authentication code is required.',
            'code.regex' => 'The authentication code must contain exactly 6 digits.',
        ];
    }
}