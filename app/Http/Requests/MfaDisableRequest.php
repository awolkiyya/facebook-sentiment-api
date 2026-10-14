<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MfaDisableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
            ],

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
            'password.required' => 'Your current password is required.',
            'code.required' => 'Your authenticator code is required.',
            'code.regex' => 'The authenticator code must contain exactly 6 digits.',
        ];
    }
}