<?php

declare(strict_types=1);

namespace App\Modules\Insights\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GeneratePostInsightRequest extends FormRequest
{
    /**
     * Authorize the authenticated user.
     *
     * The route middleware or policy must additionally enforce
     * the Facebook post-insights generation permission.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for generating Facebook post insights.
     */
    public function rules(): array
    {
        $allowedModels = config(
            'services.degertu_ai.allowed_models',
            [],
        );

        if (! is_array($allowedModels)) {
            $allowedModels = [];
        }

        $allowedModels = array_values(array_filter(
            $allowedModels,
            static fn (mixed $model): bool =>
                is_string($model) && $model !== '',
        ));

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
                'array:from,to',
                'required_array_keys:from,to',
            ],

            'date_range.from' => [
                'required_with:date_range',
                'date_format:Y-m-d',
            ],

            'date_range.to' => [
                'required_with:date_range',
                'date_format:Y-m-d',
                'after_or_equal:date_range.from',
            ],
        ];
    }

    /**
     * Validate the complete request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dateRange = $this->input('date_range');

            if (! is_array($dateRange)) {
                return;
            }

            $from = $dateRange['from'] ?? null;
            $to = $dateRange['to'] ?? null;

            if (
                is_string($from)
                && is_string($to)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
                && $from > $to
            ) {
                $validator->errors()->add(
                    'date_range.to',
                    'The end date must be on or after the start date.',
                );
            }
        });
    }

    /**
     * Human-readable validation messages.
     */
    public function messages(): array
    {
        return [
            'language.required' =>
                'Please select a language for the generated insights.',

            'language.in' =>
                'The language must be English, Amharic, or Afaan Oromo.',

            'model.in' =>
                'The selected Degertu AI model is not supported.',

            'regenerate.boolean' =>
                'The regenerate field must be true or false.',

            'date_range.array' =>
                'The date range must contain a start date and an end date.',

            'date_range.required_array_keys' =>
                'Both the start date and end date are required when specifying a date range.',

            'date_range.from.required_with' =>
                'The start date is required when specifying a date range.',

            'date_range.from.date_format' =>
                'The start date must use the YYYY-MM-DD format.',

            'date_range.to.required_with' =>
                'The end date is required when specifying a date range.',

            'date_range.to.date_format' =>
                'The end date must use the YYYY-MM-DD format.',

            'date_range.to.after_or_equal' =>
                'The end date must be on or after the start date.',
        ];
    }
}