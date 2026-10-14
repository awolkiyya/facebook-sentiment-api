<?php

namespace App\Services;

use App\Supports\ApiErrorCode;
use App\Supports\ApiErrorType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * ApiResponse
 *
 * Canonical JSON response builder for the application's API.
 *
 * Success response:
 *
 * {
 *   "success": true,
 *   "message": "Posts retrieved successfully.",
 *   "data": [],
 *   "meta": {
 *     "timestamp": "2026-09-04T20:00:00+00:00",
 *     "request_id": "01K...",
 *     "version": "v1"
 *   }
 * }
 *
 * Error response:
 *
 * {
 *   "success": false,
 *   "message": "Authentication is required.",
 *   "error": {
 *     "code": "UNAUTHENTICATED",
 *     "type": "AUTHENTICATION_ERROR",
 *     "details": null
 *   },
 *   "meta": {
 *     "timestamp": "2026-09-04T20:00:00+00:00",
 *     "request_id": "01K...",
 *     "version": "v1"
 *   }
 * }
 *
 * Design principles:
 *
 * - One predictable JSON envelope.
 * - Machine-readable error codes.
 * - Broad error categories through error types.
 * - Human-readable messages.
 * - Structured error details.
 * - Request ID correlation.
 * - API version metadata.
 * - Automatic pagination metadata.
 * - No internal exception details exposed through the API.
 * - HTTP status codes remain semantically correct.
 */
class ApiResponse
{
    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    */

    protected static function version(): string
    {
        return config('api.version', 'v1');
    }

    /*
    |--------------------------------------------------------------------------
    | Request ID
    |--------------------------------------------------------------------------
    */

    /**
     * Return the request ID assigned by middleware.
     *
     * The middleware should be the authoritative source.
     * Fallbacks exist for cases where ApiResponse is used outside
     * the normal HTTP middleware pipeline.
     */
    protected static function requestId(): string
    {
        return request()->attributes->get('request_id')
            ?? request()->header('X-Request-Id')
            ?? (string) Str::uuid();
    }

    /*
    |--------------------------------------------------------------------------
    | Base Metadata
    |--------------------------------------------------------------------------
    */

    protected static function baseMeta(array $extra = []): array
    {
        return array_merge(
            [
                'timestamp' => now()->toIso8601String(),
                'request_id' => static::requestId(),
                'version'    => static::version(),
            ],
            $extra
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Data Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize Laravel API resources and automatically extract
     * pagination metadata.
     */
    protected static function normalizeData(
        mixed $data,
        array &$meta
    ): mixed {
        /*
        |--------------------------------------------------------------------------
        | Resource Collection
        |--------------------------------------------------------------------------
        */

        if ($data instanceof ResourceCollection) {
            $resource = $data->resource;

            if ($resource instanceof LengthAwarePaginator) {
                $meta['pagination'] = [
                    'current_page' => $resource->currentPage(),
                    'per_page'     => $resource->perPage(),
                    'total'        => $resource->total(),
                    'last_page'    => $resource->lastPage(),
                    'from'         => $resource->firstItem(),
                    'to'           => $resource->lastItem(),
                    'has_more'     => $resource->hasMorePages(),
                ];

                return $data;
            }

            return $data;
        }

        /*
        |--------------------------------------------------------------------------
        | Single JSON Resource
        |--------------------------------------------------------------------------
        */

        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Data
        |--------------------------------------------------------------------------
        */

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Response Builder
    |--------------------------------------------------------------------------
    */

    protected static function respond(
        bool $success,
        string $message,
        mixed $data = null,
        ?array $error = null,
        int $status = 200,
        array $meta = [],
        mixed $summary = null
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Normalize Data
        |--------------------------------------------------------------------------
        */

        $data = static::normalizeData($data, $meta);

        /*
        |--------------------------------------------------------------------------
        | Optional Summary
        |--------------------------------------------------------------------------
        */

        if ($summary !== null) {
            $meta['summary'] = $summary;
        }

        /*
        |--------------------------------------------------------------------------
        | Base Payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'success' => $success,
            'message' => $message,
            'data'    => $success ? $data : null,
        ];

        /*
        |--------------------------------------------------------------------------
        | Error Object
        |--------------------------------------------------------------------------
        */

        if (! $success) {
            $payload['error'] = $error;
        }

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $payload['meta'] = static::baseMeta($meta);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()
            ->json($payload, $status)
            ->header('X-Request-Id', static::requestId());
    }

    /*
    |--------------------------------------------------------------------------
    | 2xx — Success
    |--------------------------------------------------------------------------
    */

    public static function success(
        mixed $data = null,
        string $message = 'Success',
        array $meta = [],
        int $status = 200,
        mixed $summary = null
    ): JsonResponse {
        return static::respond(
            success: true,
            message: $message,
            data: $data,
            error: null,
            status: $status,
            meta: $meta,
            summary: $summary,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 201 — Created
    |--------------------------------------------------------------------------
    */

    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully.',
        array $meta = [],
        mixed $summary = null
    ): JsonResponse {
        return static::respond(
            success: true,
            message: $message,
            data: $data,
            error: null,
            status: 201,
            meta: $meta,
            summary: $summary,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 202 — Accepted
    |--------------------------------------------------------------------------
    */

    /**
     * Useful for asynchronous operations such as:
     *
     * - Facebook synchronization
     * - AI analysis
     * - Report generation
     * - Background processing
     */
    public static function accepted(
        mixed $data = null,
        string $message = 'The request has been accepted for processing.',
        array $meta = [],
        mixed $summary = null
    ): JsonResponse {
        return static::respond(
            success: true,
            message: $message,
            data: $data,
            error: null,
            status: 202,
            meta: $meta,
            summary: $summary,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 200 — Updated
    |--------------------------------------------------------------------------
    */

    public static function updated(
        mixed $data = null,
        string $message = 'Resource updated successfully.',
        array $meta = [],
        mixed $summary = null
    ): JsonResponse {
        return static::respond(
            success: true,
            message: $message,
            data: $data,
            error: null,
            status: 200,
            meta: $meta,
            summary: $summary,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 204 — No Content
    |--------------------------------------------------------------------------
    */

    /**
     * HTTP 204 responses must not contain a response body.
     */
    public static function deleted(
        string $message = 'Resource deleted successfully.'
    ): JsonResponse {
        return response()
            ->json(null, 204)
            ->header('X-Request-Id', static::requestId());
    }

    /*
    |--------------------------------------------------------------------------
    | Generic Error
    |--------------------------------------------------------------------------
    */

    public static function error(
        string $message = 'An error occurred.',
        int $status = 400,
        ApiErrorCode|string $code = ApiErrorCode::ERROR,
        ApiErrorType|string $type = ApiErrorType::CLIENT_ERROR,
        mixed $details = null,
        array $meta = []
    ): JsonResponse {
        $error = [
            'code'    => $code instanceof ApiErrorCode
                ? $code->value
                : $code,

            'type'    => $type instanceof ApiErrorType
                ? $type->value
                : $type,

            'details' => $details,
        ];

        return static::respond(
            success: false,
            message: $message,
            data: null,
            error: $error,
            status: $status,
            meta: $meta,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 422 — Validation
    |--------------------------------------------------------------------------
    */

    public static function validation(
        mixed $details,
        string $message = 'The given data was invalid.'
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 422,
            code: ApiErrorCode::VALIDATION_FAILED,
            type: ApiErrorType::VALIDATION_ERROR,
            details: [
                'fields' => $details,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 401 — Unauthenticated
    |--------------------------------------------------------------------------
    */

    public static function unauthorized(
        string $message = 'Authentication is required.'
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 401,
            code: ApiErrorCode::UNAUTHENTICATED,
            type: ApiErrorType::AUTHENTICATION_ERROR,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 403 — Forbidden
    |--------------------------------------------------------------------------
    */

    public static function forbidden(
        string $message = 'You do not have permission to perform this action.'
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 403,
            code: ApiErrorCode::FORBIDDEN,
            type: ApiErrorType::AUTHORIZATION_ERROR,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 404 — Not Found
    |--------------------------------------------------------------------------
    */

    public static function notFound(
        string $message = 'The requested resource was not found.',
        ApiErrorCode|string $code = ApiErrorCode::RESOURCE_NOT_FOUND,
        mixed $details = null
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 404,
            code: $code,
            type: ApiErrorType::NOT_FOUND_ERROR,
            details: $details,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 409 — Conflict
    |--------------------------------------------------------------------------
    */

    public static function conflict(
        string $message = 'The request conflicts with the current state of the resource.',
        mixed $details = null,
        ApiErrorCode|string $code = ApiErrorCode::CONFLICT
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 409,
            code: $code,
            type: ApiErrorType::CONFLICT_ERROR,
            details: $details,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 423 — Locked
    |--------------------------------------------------------------------------
    */

    public static function locked(
        string $message = 'The requested resource is locked.',
        mixed $details = null,
        ApiErrorCode|string $code = ApiErrorCode::RESOURCE_LOCKED
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 423,
            code: $code,
            type: ApiErrorType::BUSINESS_ERROR,
            details: $details,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 429 — Too Many Requests
    |--------------------------------------------------------------------------
    */

    public static function tooManyRequests(
        string $message = 'Too many requests. Please try again later.',
        ?int $retryAfterSeconds = null
    ): JsonResponse {
        $response = static::error(
            message: $message,
            status: 429,
            code: ApiErrorCode::RATE_LIMIT_EXCEEDED,
            type: ApiErrorType::RATE_LIMIT_ERROR,
        );

        if ($retryAfterSeconds !== null) {
            $response->header(
                'Retry-After',
                (string) $retryAfterSeconds
            );
        }

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | 500 — Internal Server Error
    |--------------------------------------------------------------------------
    */

    /**
     * Generic server error.
     *
     * Exception details must be logged by the exception handler.
     * They are intentionally NOT accepted here and are never returned
     * to the client.
     */
    public static function serverError(
        string $message = 'Something went wrong on our end. Please try again later.'
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 500,
            code: ApiErrorCode::INTERNAL_SERVER_ERROR,
            type: ApiErrorType::SERVER_ERROR,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 500 — Database Error
    |--------------------------------------------------------------------------
    */

    public static function databaseError(
        string $message = 'A database error occurred. Please try again later.'
    ): JsonResponse {
        return static::error(
            message: $message,
            status: 500,
            code: ApiErrorCode::DATABASE_ERROR,
            type: ApiErrorType::SERVER_ERROR,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 502/503 — External Service
    |--------------------------------------------------------------------------
    */

    public static function externalServiceError(
        string $message = 'An external service is temporarily unavailable.',
        mixed $details = null,
        int $status = 503
    ): JsonResponse {
        return static::error(
            message: $message,
            status: $status,
            code: ApiErrorCode::EXTERNAL_SERVICE_ERROR,
            type: ApiErrorType::EXTERNAL_SERVICE_ERROR,
            details: $details,
        );
    }
}