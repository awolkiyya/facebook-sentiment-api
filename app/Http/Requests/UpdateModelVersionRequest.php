<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModelVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $modelClass = $this->route('pipeline')->model();

        $table = (new $modelClass())->getTable();

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'version' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique($table, 'version')
                    ->where(
                        fn ($query) => $query->where(
                            'name',
                            $this->input('name'),
                        ),
                    )
                    ->ignore($this->route('modelVersion')),
            ],

            'model_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'framework' => [
                'nullable',
                'string',
                'max:100',
            ],

            'accuracy' => [
                'nullable',
                'numeric',
                'between:0,1',
            ],

            'macro_f1' => [
                'nullable',
                'numeric',
                'between:0,1',
            ],

            'supported_languages' => [
                'nullable',
                'array',
            ],

            'supported_languages.*' => [
                'string',
                'max:10',
            ],

            /*
             * is_active is intentionally excluded.
             *
             * Activation is handled exclusively through the
             * dedicated activate() endpoint.
             */
        ];
    }
}
