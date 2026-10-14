<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModelVersionRequest extends FormRequest
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
                'required',
                'string',
                'max:255',
            ],

            'version' => [
                'required',
                'string',
                'max:50',
                Rule::unique($table, 'version')->where(
                    fn ($query) => $query->where(
                        'name',
                        $this->input('name'),
                    ),
                ),
            ],

            'model_name' => [
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
             * is_active is intentionally not accepted here.
             *
             * Activation must go through the dedicated activate()
             * endpoint because activation/deactivation is a state
             * transition handled transactionally.
             */
        ];
    }
}
