<?php

namespace App\Modules\Administration\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_\- ]*$/',
                Rule::unique('roles', 'name')
                    ->where(
                        fn ($query) => $query->where(
                            'guard_name',
                            'api'
                        )
                    ),
            ],

            'permissions' => [
                'sometimes',
                'array',
            ],

            'permissions.*' => [
                'required',
                'string',
                'distinct',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' =>
                'The role name may contain letters, numbers, spaces, hyphens, and underscores.',

            'permissions.array' =>
                'Permissions must be provided as an array.',

            'permissions.*.distinct' =>
                'Duplicate permissions are not allowed.',
        ];
    }
}