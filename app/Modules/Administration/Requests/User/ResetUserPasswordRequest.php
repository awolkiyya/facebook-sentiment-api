<?php

namespace App\Modules\Administration\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.reset_password') ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'min:12',
                'max:255',
                'confirmed',
            ],
        ];
    }
}