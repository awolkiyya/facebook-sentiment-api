<?php

namespace App\Http\Requests;

use App\Supports\SystemLogAction;
use App\Supports\SystemLogModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemLogIndexRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Search
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            // Filters
            'action' => [
                'nullable',
                'string',
                'max:100',
                Rule::in($this->allowedActions()),
            ],

            'module' => [
                'nullable',
                'string',
                'max:100',
                Rule::in($this->allowedModules()),
            ],

            'status' => [
                'nullable',
                'string',
                'uppercase',
                Rule::in([
                    'SUCCESS',
                    'FAILED',
                    'DENIED',
                ]),
            ],

            'severity' => [
                'nullable',
                'string',
                'uppercase',
                Rule::in([
                    'INFO',
                    'WARNING',
                    'ERROR',
                    'CRITICAL',
                ]),
            ],

            'user_id' => [
                'nullable',
                'uuid',
                'exists:users,id',
            ],

            'resource_type' => [
                'nullable',
                'string',
                'max:255',
            ],

            'resource_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'request_id' => [
                'nullable',
                'uuid',
            ],

            'ip_address' => [
                'nullable',
                'ip',
            ],

            // Date filters
            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],

            // Pagination
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            // Sorting
            'sort_by' => [
                'nullable',
                'string',
                Rule::in([
                    'id',
                    'created_at',
                    'action',
                    'module',
                    'status',
                    'severity',
                    'user_id',
                    'resource_type',
                    'resource_id',
                    'request_id',
                    'duration_ms',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                'string',
                'lowercase',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],
        ];
    }

    /**
     * Normalize request values before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->normalizeString(
                $this->input('search')
            ),

            'action' => $this->normalizeUppercase(
                $this->input('action')
            ),

            'module' => $this->normalizeUppercase(
                $this->input('module')
            ),

            'status' => $this->normalizeUppercase(
                $this->input('status')
            ),

            'severity' => $this->normalizeUppercase(
                $this->input('severity')
            ),

            'resource_type' => $this->normalizeString(
                $this->input('resource_type')
            ),

            'resource_id' => $this->normalizeString(
                $this->input('resource_id')
            ),

            'request_id' => $this->normalizeString(
                $this->input('request_id')
            ),

            'ip_address' => $this->normalizeString(
                $this->input('ip_address')
            ),

            'sort_by' => $this->normalizeString(
                $this->input('sort_by')
            ),

            'sort_direction' => $this->normalizeLowercase(
                $this->input('sort_direction')
            ),
        ]);
    }

    /**
     * Get validated filters with sensible defaults.
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => $validated['search'] ?? null,
            'action' => $validated['action'] ?? null,
            'module' => $validated['module'] ?? null,
            'status' => $validated['status'] ?? null,
            'severity' => $validated['severity'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'resource_type' => $validated['resource_type'] ?? null,
            'resource_id' => $validated['resource_id'] ?? null,
            'request_id' => $validated['request_id'] ?? null,
            'ip_address' => $validated['ip_address'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 20,
            'page' => $validated['page'] ?? 1,
            'sort_by' => $validated['sort_by'] ?? 'created_at',
            'sort_direction' => $validated['sort_direction'] ?? 'desc',
        ];
    }

    /**
     * Allowed audit actions.
     *
     * Keep this synchronized with SystemLogAction.
     */
    private function allowedActions(): array
    {
        return [
            SystemLogAction::CREATE,
            SystemLogAction::UPDATE,
            SystemLogAction::DELETE,
            SystemLogAction::VIEW,

            SystemLogAction::LOGIN,
            SystemLogAction::LOGIN_FAILED,
            SystemLogAction::LOGOUT,
            SystemLogAction::PASSWORD_CHANGED,
            SystemLogAction::PASSWORD_RESET,

            SystemLogAction::PERMISSION_DENIED,
            SystemLogAction::SECURITY_EVENT,

            SystemLogAction::CONNECT,
            SystemLogAction::DISCONNECT,
            SystemLogAction::SYNC,
            SystemLogAction::SYNC_STARTED,
            SystemLogAction::SYNC_COMPLETED,
            SystemLogAction::SYNC_FAILED,
            SystemLogAction::WEBHOOK_RECEIVED,
            SystemLogAction::RATE_LIMIT_WARNING,

            SystemLogAction::POST_CREATED,
            SystemLogAction::POST_UPDATED,
            SystemLogAction::COMMENT_CREATED,
            SystemLogAction::COMMENT_UPDATED,
            SystemLogAction::REACTION_SYNCED,
            SystemLogAction::INSIGHTS_SYNCED,

            SystemLogAction::ANALYZE,
            SystemLogAction::ANALYSIS_STARTED,
            SystemLogAction::ANALYSIS_COMPLETED,
            SystemLogAction::ANALYSIS_FAILED,
            SystemLogAction::SENTIMENT_ANALYZED,
            SystemLogAction::BATCH_ANALYSIS,

            SystemLogAction::EXPORT,
            SystemLogAction::DOWNLOAD,
            SystemLogAction::REPORT_GENERATED,

            SystemLogAction::SYSTEM_EVENT,
            SystemLogAction::STATUS_CHANGED,
            SystemLogAction::CACHE_CLEARED,
            SystemLogAction::ERROR,
        ];
    }

    /**
     * Allowed audit modules.
     *
     * Keep this synchronized with SystemLogModule.
     */
    private function allowedModules(): array
    {
        return [
            SystemLogModule::AUTHENTICATION,
            SystemLogModule::USERS,
            SystemLogModule::ROLES,
            SystemLogModule::PERMISSIONS,
            SystemLogModule::SECURITY,

            SystemLogModule::FACEBOOK,
            SystemLogModule::FACEBOOK_PAGES,
            SystemLogModule::FACEBOOK_POSTS,
            SystemLogModule::FACEBOOK_COMMENTS,
            SystemLogModule::FACEBOOK_REACTIONS,
            SystemLogModule::FACEBOOK_INSIGHTS,

            SystemLogModule::AI,
            SystemLogModule::SENTIMENT,

            SystemLogModule::REPORTS,
            SystemLogModule::API,
            SystemLogModule::SYSTEM,
        ];
    }

    private function normalizeString(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizeUppercase(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return strtoupper(trim((string) $value));
    }

    private function normalizeLowercase(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return strtolower(trim((string) $value));
    }
}