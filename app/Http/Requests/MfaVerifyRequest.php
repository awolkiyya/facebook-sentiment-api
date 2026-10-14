<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MfaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => [
                'required',
                'uuid',
            ],

            'code' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'challenge_id.required' => 'The MFA challenge ID is required.',

            'challenge_id.uuid' => 'The MFA challenge ID must be a valid UUID.',

            'code.required' => 'The authentication or recovery code is required.',
        ];
    }
}