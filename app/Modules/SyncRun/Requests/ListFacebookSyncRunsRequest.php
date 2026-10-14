<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Requests;

use App\Enums\FacebookSyncStatus;
use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListFacebookSyncRunsRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may list synchronization runs.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for listing synchronization runs.
     *
     * This request validates the shape of the filters.
     *
     * Access to specific Facebook pages must still be enforced by the
     * controller/query authorization layer.
     */
    public function rules(): array
    {
        return [
            'facebook_page_id' => [
                'sometimes',
                'nullable',
                'uuid',
                'exists:facebook_pages,id',
            ],

            'status' => [
                'sometimes',
                'nullable',
                Rule::enum(FacebookSyncStatus::class),
            ],

            'trigger' => [
                'sometimes',
                'nullable',
                Rule::enum(FacebookSyncTrigger::class),
            ],

            'type' => [
                'sometimes',
                'nullable',
                Rule::enum(FacebookSyncType::class),
            ],

            'per_page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * Normalize incoming filter and pagination values before validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        /*
        |--------------------------------------------------------------------------
        | String filters
        |--------------------------------------------------------------------------
        */
        foreach ([
            'facebook_page_id',
            'status',
            'trigger',
            'type',
        ] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            /*
             * Treat an empty query-string value as null.
             *
             * Example:
             * ?status=
             *
             * becomes:
             * status => null
             */
            $data[$field] = $value === '' ? null : $value;
        }

        /*
        |--------------------------------------------------------------------------
        | Enum filters
        |--------------------------------------------------------------------------
        |
        | Your enum values are expected to be lowercase values such as:
        | posts, comments, full, incremental, queued, running, etc.
        |
        */
        foreach ([
            'status',
            'trigger',
            'type',
        ] as $field) {
            if (
                array_key_exists($field, $data)
                && is_string($data[$field])
            ) {
                $data[$field] = strtolower($data[$field]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination normalization
        |--------------------------------------------------------------------------
        */
        foreach ([
            'per_page',
            'page',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);

                $data[$field] = $value === ''
                    ? null
                    : $value;
            }
        }

        $this->merge($data);
    }

    /**
     * Get the requested page size.
     */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage)
            ? (int) $perPage
            : 20;
    }

    /**
     * Get the requested page number.
     */
    public function pageNumber(): int
    {
        $page = $this->validated('page');

        return is_numeric($page)
            ? (int) $page
            : 1;
    }
}