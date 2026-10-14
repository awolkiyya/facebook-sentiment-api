<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Requests;

use App\Enums\FacebookSyncType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartFacebookSyncRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for starting a Facebook synchronization.
     *
     * The Facebook Page ID is the internal UUID from facebook_pages.id.
     *
     * The Meta/Facebook external page ID must NOT be accepted here.
     */
    public function rules(): array
    {
        return [
            'facebook_page_id' => [
                'required',
                'uuid',
                'exists:facebook_pages,id',
            ],

            'type' => [
                'required',
                Rule::enum(FacebookSyncType::class),
            ],
        ];
    }

    /**
     * Prepare normalized input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'facebook_page_id' => is_string($this->input('facebook_page_id'))
                ? trim($this->input('facebook_page_id'))
                : $this->input('facebook_page_id'),

            'type' => is_string($this->input('type'))
                ? strtolower(trim($this->input('type')))
                : $this->input('type'),
        ]);
    }

    /**
     * Return validated Facebook Page ID.
     */
    public function facebookPageId(): string
    {
        return (string) $this->validated('facebook_page_id');
    }

    /**
     * Return validated sync type.
     */
    public function syncType(): FacebookSyncType
    {
        /** @var FacebookSyncType $type */
        $type = $this->validated('type');

        return $type;
    }
}