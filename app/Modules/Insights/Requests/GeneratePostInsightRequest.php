<?php

namespace App\Modules\Insights\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneratePostInsightRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enforce the appropriate Facebook insights permission
        // through your existing route middleware or policy.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $allowedModels = config(
            'services.degertu_ai.allowed_models',
            [],
        );

        if (! is_array($allowedModels)) {
            $allowedModels = [];
        }

        return [
            'language' => [
                'required',
                'string',
                Rule::in(['en', 'am', 'or']),
            ],

            'model' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::in($allowedModels),
            ],

            'regenerate' => [
                'sometimes',
                'boolean',
            ],

            'date_range' => [
                'sometimes',
                'array',
            ],

            'date_range.from' => [
                'nullable',
                'date_format:Y-m-d',
                'required_with:date_range.to',
            ],

            'date_range.to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_range.from',
                'required_with:date_range.from',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'language.in' =>
                'The language must be English, Amharic, or Afaan Oromo.',

            'model.in' =>
                'The selected Degertu AI model is not supported.',

            'regenerate.boolean' =>
                'The regenerate field must be true or false.',

            'date_range.to.after_or_equal' =>
                'The end date must be on or after the start date.',
        ];
    }
}