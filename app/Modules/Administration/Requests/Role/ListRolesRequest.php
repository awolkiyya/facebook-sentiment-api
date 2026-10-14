<?php

namespace App\Modules\Administration\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class ListRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'sort_by' => [
                'sometimes',
                'nullable',
                'string',
                'in:name,created_at,updated_at,users_count,permissions_count',
            ],

            'sort_direction' => [
                'sometimes',
                'nullable',
                'string',
                'in:asc,desc',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }
}