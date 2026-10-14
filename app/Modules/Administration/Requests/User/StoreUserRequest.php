<?php

namespace App\Modules\Administration\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:12',
                'max:255',
                'confirmed',
            ],

            'roles' => [
                'sometimes',
                'array',
            ],

            'roles.*' => [
                'string',
                'max:100',
                Rule::exists('roles', 'name')
                    ->where('guard_name', 'api'),
            ],
        ];
    }
}