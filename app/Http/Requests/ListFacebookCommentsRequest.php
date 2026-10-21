<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListFacebookCommentsRequest extends FormRequest
{
    /**
     * Page and post authorization is handled by the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'sentiment' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'positive',
                    'neutral',
                    'negative',
                ]),
            ],

            'language' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'en',
                    'am',
                    'om',
                ]),
            ],

            /*
             * Only expose statuses that the current controller
             * can actually filter correctly.
             *
             * "pending" means no persisted analysis exists.
             * "completed" means a persisted analysis exists.
             *
             * Add "processing" and "failed" only after implementing
             * persistent status tracking and filtering.
             */
            'analysis_status' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'pending',
                    'completed',
                ]),
            ],

            /*
             * Every accepted sort value must have corresponding
             * ordering logic in the controller.
             */
            'sort_by' => [
                'sometimes',
                Rule::in([
                    'newest',
                    'oldest',
                    'most_liked',
                    'most_replies',
                    'highest_sentiment_confidence',
                    'highest_priority',
                ]),
            ],

            'start_date' => [
                'sometimes',
                'nullable',
                'required_with:end_date',
                'date_format:Y-m-d',
                'before_or_equal:end_date',
            ],

            'end_date' => [
                'sometimes',
                'nullable',
                'required_with:start_date',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
        ];
    }
}