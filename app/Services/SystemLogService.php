<?php

namespace App\Services;

use App\Models\SystemLog;
use App\Models\User;
use App\Supports\SystemLogAction;
use App\Supports\SystemLogModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SystemLogService
{
    /*
    |--------------------------------------------------------------------------
    | LOG STATUS
    |--------------------------------------------------------------------------
    */

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_DENIED = 'DENIED';


    /*
    |--------------------------------------------------------------------------
    | LOG SEVERITY
    |--------------------------------------------------------------------------
    */

    public const SEVERITY_INFO = 'INFO';

    public const SEVERITY_WARNING = 'WARNING';

    public const SEVERITY_ERROR = 'ERROR';

    public const SEVERITY_CRITICAL = 'CRITICAL';


    /*
    |--------------------------------------------------------------------------
    | SENSITIVE FIELDS
    |--------------------------------------------------------------------------
    |
    | These fields must NEVER be persisted into audit logs.
    |
    */

    private const SENSITIVE_FIELDS = [
        // Authentication
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',

        // Generic tokens
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'remember_token',
        'bearer_token',

        // HTTP authentication
        'authorization',
        'cookie',
        'set_cookie',

        // Secrets
        'secret',
        'client_secret',
        'client_secret_id',
        'private_key',
        'secret_key',
        'encryption_key',
        'encryption_secret',
        'signing_secret',
        'webhook_secret',

        // Application secrets
        'app_secret',
        'app_secret_proof',

        // OAuth
        'oauth_token',
        'oauth_access_token',
        'oauth_refresh_token',

        // Facebook / Meta
        'facebook_access_token',
        'facebook_page_access_token',
        'page_access_token',
        'graph_api_token',
        'meta_access_token',
        'signed_request',

        // Credentials
        'credential',
        'credentials',
    ];


    /*
    |--------------------------------------------------------------------------
    | RECORD GENERIC SYSTEM EVENT
    |--------------------------------------------------------------------------
    */

    public function log(
        string $action,
        ?string $module = null,
        ?Model $resource = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        try {
            /*
            |--------------------------------------------------------------------------
            | REQUEST
            |--------------------------------------------------------------------------
            */

            $request = $this->resolveRequest();


            /*
            |--------------------------------------------------------------------------
            | ACTOR
            |--------------------------------------------------------------------------
            */

            $actor = $this->resolveActor($actor);


            /*
            |--------------------------------------------------------------------------
            | RESOURCE
            |--------------------------------------------------------------------------
            */

            $resourceType = null;

            $resourceId = null;

            if ($resource instanceof Model) {
                $resourceType = $resource->getMorphClass();

                $resourceId = $resource->getKey();
            }


            /*
            |--------------------------------------------------------------------------
            | REQUEST ID
            |--------------------------------------------------------------------------
            */

            $requestId = $this->resolveRequestId($request);


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE ACTION
            |--------------------------------------------------------------------------
            */

            $action = strtoupper(trim($action));


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE MODULE
            |--------------------------------------------------------------------------
            */

            $module = $module !== null
                ? strtoupper(trim($module))
                : null;


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE STATUS
            |--------------------------------------------------------------------------
            */

            $status = $this->normalizeStatus($status);


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE SEVERITY
            |--------------------------------------------------------------------------
            */

            $severity = $this->normalizeSeverity($severity);


            /*
            |--------------------------------------------------------------------------
            | RESOLVE DURATION
            |--------------------------------------------------------------------------
            |
            | If the caller explicitly provides durationMs, use it.
            |
            | Otherwise, calculate the elapsed request duration from
            | request_started_at, which is populated by the
            | TrackRequestStartTime middleware.
            |
            */

            $durationMs = $this->resolveDurationMs(
                $request,
                $durationMs
            );


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE DURATION
            |--------------------------------------------------------------------------
            */

            $durationMs = $this->normalizeDuration(
                $durationMs
            );


            /*
            |--------------------------------------------------------------------------
            | BUILD LOG DATA
            |--------------------------------------------------------------------------
            */

            $data = [
                'user_id' => $actor?->getKey(),

                'action' => $action,

                'module' => $module,

                'status' => $status,

                'severity' => $severity,

                'resource_type' => $resourceType,

                'resource_id' => $resourceId !== null
                    ? (string) $resourceId
                    : null,

                'description' => $description,

                'old_values' => $this->sanitize(
                    $oldValues
                ),

                'new_values' => $this->sanitize(
                    $newValues
                ),

                'request_id' => $requestId,

                'http_method' => $request?->method(),

                'endpoint' => $request?->getPathInfo(),

                'ip_address' => $request?->ip(),

                'user_agent' => $this->truncateUserAgent(
                    $request?->userAgent()
                ),

                'duration_ms' => $durationMs,

                'metadata' => $this->sanitize(
                    $metadata
                ),

                'created_at' => now(),
            ];


            /*
            |--------------------------------------------------------------------------
            | PERSIST
            |--------------------------------------------------------------------------
            */

            return SystemLog::create($data);

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Audit logging must NEVER break the main business operation.
            |
            */

            Log::error(
                'SystemLogService: failed to create audit log',
                [
                    'action' => $action ?? null,

                    'module' => $module ?? null,

                    'resource_type' =>
                        $resource?->getMorphClass(),

                    'resource_id' =>
                        $resource?->getKey(),

                    'exception' =>
                        get_class($exception),

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RECORD COLLECTION / LIST EVENT
    |--------------------------------------------------------------------------
    */

    public function logCollection(
        string $action,
        ?string $module = null,
        ?string $resourceType = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        try {
            /*
            |--------------------------------------------------------------------------
            | REQUEST
            |--------------------------------------------------------------------------
            */

            $request = $this->resolveRequest();


            /*
            |--------------------------------------------------------------------------
            | ACTOR
            |--------------------------------------------------------------------------
            */

            $actor = $this->resolveActor($actor);


            /*
            |--------------------------------------------------------------------------
            | REQUEST ID
            |--------------------------------------------------------------------------
            */

            $requestId = $this->resolveRequestId($request);


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE
            |--------------------------------------------------------------------------
            */

            $action = strtoupper(trim($action));

            $module = $module !== null
                ? strtoupper(trim($module))
                : null;

            $resourceType = $resourceType !== null
                ? trim($resourceType)
                : null;

            $status = $this->normalizeStatus($status);

            $severity = $this->normalizeSeverity($severity);


            /*
            |--------------------------------------------------------------------------
            | RESOLVE DURATION
            |--------------------------------------------------------------------------
            */

            $durationMs = $this->resolveDurationMs(
                $request,
                $durationMs
            );


            /*
            |--------------------------------------------------------------------------
            | NORMALIZE DURATION
            |--------------------------------------------------------------------------
            */

            $durationMs = $this->normalizeDuration(
                $durationMs
            );


            /*
            |--------------------------------------------------------------------------
            | PERSIST COLLECTION EVENT
            |--------------------------------------------------------------------------
            */

            return SystemLog::create([
                'user_id' => $actor?->getKey(),

                'action' => $action,

                'module' => $module,

                'status' => $status,

                'severity' => $severity,

                /*
                |--------------------------------------------------------------------------
                | Collection Resource
                |--------------------------------------------------------------------------
                |
                | resource_id intentionally remains NULL.
                |
                */

                'resource_type' => $resourceType,

                'resource_id' => null,

                'description' => $description,

                'old_values' => $this->sanitize(
                    $oldValues
                ),

                'new_values' => $this->sanitize(
                    $newValues
                ),

                'request_id' => $requestId,

                'http_method' => $request?->method(),

                'endpoint' => $request?->getPathInfo(),

                'ip_address' => $request?->ip(),

                'user_agent' => $this->truncateUserAgent(
                    $request?->userAgent()
                ),

                'duration_ms' => $durationMs,

                'metadata' => $this->sanitize(
                    $metadata
                ),

                'created_at' => now(),
            ]);

        } catch (Throwable $exception) {

            Log::error(
                'SystemLogService: failed to create collection audit log',
                [
                    'action' => $action ?? null,

                    'module' => $module ?? null,

                    'resource_type' =>
                        $resourceType ?? null,

                    'exception' =>
                        get_class($exception),

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function created(
        Model $resource,
        string $module,
        ?string $description = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        $newValues ??=
            $this->safeModelAttributes($resource);

        return $this->log(
            action: SystemLogAction::CREATE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' created successfully',
            newValues: $newValues,
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function updated(
        Model $resource,
        string $module,
        array $oldValues,
        ?array $newValues = null,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        $newValues ??=
            $this->safeModelAttributes($resource);

        return $this->log(
            action: SystemLogAction::UPDATE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' updated successfully',
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function deleted(
        Model $resource,
        string $module,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        $oldValues ??=
            $this->safeModelAttributes($resource);

        return $this->log(
            action: SystemLogAction::DELETE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' deleted successfully',
            oldValues: $oldValues,
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VIEW RESOURCE
    |--------------------------------------------------------------------------
    */

    public function viewed(
        ?Model $resource = null,
        string $module = SystemLogModule::SYSTEM,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::VIEW,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? (
                    $resource
                        ? class_basename($resource) . ' viewed'
                        : 'Resource viewed'
                ),
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VIEW COLLECTION
    |--------------------------------------------------------------------------
    */

    public function viewedCollection(
        string $resourceType,
        string $module,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->logCollection(
            action: SystemLogAction::VIEW,
            module: $module,
            resourceType: $resourceType,
            description:
                $description
                ?? "{$resourceType} collection viewed",
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATION
    |--------------------------------------------------------------------------
    */

    public function login(
        ?User $actor = null,
        ?array $metadata = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::LOGIN,
            module: SystemLogModule::AUTHENTICATION,
            description:
                'User logged in successfully',
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    public function loginFailed(
        ?string $description = null,
        ?array $metadata = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::LOGIN_FAILED,
            module: SystemLogModule::AUTHENTICATION,
            description:
                $description
                ?? 'User login attempt failed',
            metadata: $metadata,
            status: self::STATUS_FAILED,
            severity: self::SEVERITY_WARNING,
            durationMs: $durationMs,
        );
    }


    public function logout(
        ?User $actor = null,
        ?array $metadata = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::LOGOUT,
            module: SystemLogModule::AUTHENTICATION,
            description:
                'User logged out',
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SECURITY
    |--------------------------------------------------------------------------
    */

    public function security(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_FAILED,
        string $severity = self::SEVERITY_WARNING,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::SECURITY,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PERMISSION DENIED
    |--------------------------------------------------------------------------
    */

    public function permissionDenied(
        string $permission,
        ?Model $resource = null,
        ?string $module = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::PERMISSION_DENIED,
            module:
                $module
                ?? SystemLogModule::PERMISSIONS,
            resource: $resource,
            description:
                "Permission denied: {$permission}",
            metadata: array_merge(
                $metadata ?? [],
                [
                    'permission' => $permission,
                ]
            ),
            actor: $actor,
            status: self::STATUS_DENIED,
            severity: self::SEVERITY_WARNING,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FACEBOOK
    |--------------------------------------------------------------------------
    */

    public function facebook(
        string $action,
        ?Model $resource = null,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::FACEBOOK,
            resource: $resource,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FACEBOOK PAGE
    |--------------------------------------------------------------------------
    */

    public function facebookPage(
        string $action,
        Model $page,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::FACEBOOK_PAGES,
            resource: $page,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FACEBOOK POSTS
    |--------------------------------------------------------------------------
    */

    public function facebookPost(
        string $action,
        Model $post,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::FACEBOOK_POSTS,
            resource: $post,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FACEBOOK COMMENTS
    |--------------------------------------------------------------------------
    */

    public function facebookComment(
        string $action,
        Model $comment,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::FACEBOOK_COMMENTS,
            resource: $comment,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FACEBOOK INSIGHTS
    |--------------------------------------------------------------------------
    */

    public function facebookInsights(
        string $action,
        ?Model $resource = null,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::FACEBOOK_INSIGHTS,
            resource: $resource,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SENTIMENT
    |--------------------------------------------------------------------------
    */

    public function sentiment(
        string $action,
        ?Model $resource = null,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::SENTIMENT,
            resource: $resource,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */

    public function ai(
        string $action,
        ?Model $resource = null,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::AI,
            resource: $resource,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS CHANGED
    |--------------------------------------------------------------------------
    */

    public function statusChanged(
        Model $resource,
        string $module,
        mixed $oldStatus,
        mixed $newStatus,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: SystemLogAction::STATUS_CHANGED,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? 'Status changed',
            oldValues: [
                'status' => $oldStatus,
            ],
            newValues: [
                'status' => $newStatus,
            ],
            metadata: $metadata,
            actor: $actor,
            status: self::STATUS_SUCCESS,
            severity: self::SEVERITY_INFO,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | REPORT
    |--------------------------------------------------------------------------
    */

    public function report(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::REPORTS,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    public function api(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::API,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SYSTEM
    |--------------------------------------------------------------------------
    */

    public function system(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
        string $status = self::STATUS_SUCCESS,
        string $severity = self::SEVERITY_INFO,
        ?int $durationMs = null,
    ): ?SystemLog {
        return $this->log(
            action: $action,
            module: SystemLogModule::SYSTEM,
            description: $description,
            metadata: $metadata,
            actor: $actor,
            status: $status,
            severity: $severity,
            durationMs: $durationMs,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DURATION HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Start a high-resolution timer.
     *
     * Useful when you want to measure a specific operation manually.
     *
     * Example:
     *
     * $startedAt = $systemLogService->startTimer();
     *
     * // operation...
     *
     * $durationMs = $systemLogService->elapsedMilliseconds(
     *     $startedAt
     * );
     */
    public function startTimer(): int
    {
        return hrtime(true);
    }


    /**
     * Calculate elapsed milliseconds from a manually started timer.
     */
    public function elapsedMilliseconds(
        int $startedAt
    ): int {
        return max(
            0,
            (int) round(
                (hrtime(true) - $startedAt) / 1_000_000
            )
        );
    }


    /**
     * Resolve the duration for an audit event.
     *
     * Priority:
     *
     * 1. Explicitly supplied durationMs.
     * 2. Elapsed time since request_started_at.
     * 3. NULL when there is no HTTP request timer.
     *
     * This allows both HTTP requests and background jobs to
     * use the same SystemLogService.
     */
    private function resolveDurationMs(
        ?Request $request,
        ?int $durationMs
    ): ?int {
        /*
        |--------------------------------------------------------------------------
        | Explicit duration
        |--------------------------------------------------------------------------
        |
        | If a caller provides a duration, never override it.
        |
        */

        if ($durationMs !== null) {
            return max(
                0,
                $durationMs
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Request timer
        |--------------------------------------------------------------------------
        */

        $startedAt = $request?->attributes->get(
            'request_started_at'
        );

        if (!is_numeric($startedAt)) {
            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Calculate elapsed milliseconds
        |--------------------------------------------------------------------------
        */

        return max(
            0,
            (int) round(
                (
                    microtime(true)
                    - (float) $startedAt
                ) * 1000
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ACTOR RESOLUTION
    |--------------------------------------------------------------------------
    */

    private function resolveActor(
        ?User $actor = null
    ): ?User {
        if ($actor !== null) {
            return $actor;
        }

        try {
            $actor = Auth::guard('sanctum')->user();

            if ($actor instanceof User) {
                return $actor;
            }
        } catch (Throwable) {
            //
        }

        try {
            $actor = Auth::user();

            if ($actor instanceof User) {
                return $actor;
            }
        } catch (Throwable) {
            //
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS NORMALIZATION
    |--------------------------------------------------------------------------
    */

    private function normalizeStatus(
        string $status
    ): string {
        $status = strtoupper(trim($status));

        if (!in_array(
            $status,
            [
                self::STATUS_SUCCESS,
                self::STATUS_FAILED,
                self::STATUS_DENIED,
            ],
            true
        )) {
            return self::STATUS_FAILED;
        }

        return $status;
    }


    /*
    |--------------------------------------------------------------------------
    | SEVERITY NORMALIZATION
    |--------------------------------------------------------------------------
    */

    private function normalizeSeverity(
        string $severity
    ): string {
        $severity = strtoupper(trim($severity));

        if (!in_array(
            $severity,
            [
                self::SEVERITY_INFO,
                self::SEVERITY_WARNING,
                self::SEVERITY_ERROR,
                self::SEVERITY_CRITICAL,
            ],
            true
        )) {
            return self::SEVERITY_ERROR;
        }

        return $severity;
    }


    /*
    |--------------------------------------------------------------------------
    | DURATION NORMALIZATION
    |--------------------------------------------------------------------------
    */

    private function normalizeDuration(
        ?int $durationMs
    ): ?int {
        if ($durationMs === null) {
            return null;
        }

        return max(
            0,
            $durationMs
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SANITIZATION
    |--------------------------------------------------------------------------
    */

    private function sanitize(
        mixed $value
    ): mixed {
        if ($value === null) {
            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | ARRAYS
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $normalizedKey = strtolower(
                    str_replace(
                        ['-', ' ', '.'],
                        '_',
                        (string) $key
                    )
                );

                if (
                    in_array(
                        $normalizedKey,
                        self::SENSITIVE_FIELDS,
                        true
                    )
                ) {
                    $result[$key] = '[REDACTED]';

                    continue;
                }

                $result[$key] =
                    $this->sanitize($item);
            }

            return $result;
        }


        /*
        |--------------------------------------------------------------------------
        | ELOQUENT MODEL
        |--------------------------------------------------------------------------
        */

        if ($value instanceof Model) {
            return [
                'id' => $value->getKey(),

                'type' => $value->getMorphClass(),
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | JSON SERIALIZABLE
        |--------------------------------------------------------------------------
        */

        if ($value instanceof \JsonSerializable) {
            return $this->sanitize(
                $value->jsonSerialize()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | DATE / DATETIME
        |--------------------------------------------------------------------------
        */

        if ($value instanceof \DateTimeInterface) {
            return $value->format(
                'Y-m-d\TH:i:sP'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | OTHER OBJECTS
        |--------------------------------------------------------------------------
        */

        if (is_object($value)) {
            return '[OBJECT]';
        }


        /*
        |--------------------------------------------------------------------------
        | RESOURCE
        |--------------------------------------------------------------------------
        */

        if (is_resource($value)) {
            return '[RESOURCE]';
        }


        /*
        |--------------------------------------------------------------------------
        | SCALAR
        |--------------------------------------------------------------------------
        */

        return $value;
    }


    /*
    |--------------------------------------------------------------------------
    | SAFE MODEL ATTRIBUTES
    |--------------------------------------------------------------------------
    */

    private function safeModelAttributes(
        Model $model
    ): array {
        return $this->sanitize(
            $model->getAttributes()
        );
    }


    /*
    |--------------------------------------------------------------------------
    | REQUEST
    |--------------------------------------------------------------------------
    */

    private function resolveRequest(): ?Request
    {
        try {
            if (!app()->bound('request')) {
                return null;
            }

            $request = request();

            return $request instanceof Request
                ? $request
                : null;

        } catch (Throwable) {
            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REQUEST ID
    |--------------------------------------------------------------------------
    */

    private function resolveRequestId(
        ?Request $request
    ): ?string {
        if (!$request) {
            return null;
        }

        $requestId =
            $request->header('X-Request-ID')
            ?? $request->attributes->get(
                'request_id'
            );

        if (!$requestId) {
            return null;
        }

        $requestId = trim(
            (string) $requestId
        );


        /*
        |--------------------------------------------------------------------------
        | PostgreSQL UUID protection
        |--------------------------------------------------------------------------
        */

        if (!Str::isUuid($requestId)) {
            return null;
        }

        return $requestId;
    }


    /*
    |--------------------------------------------------------------------------
    | USER AGENT
    |--------------------------------------------------------------------------
    */

    private function truncateUserAgent(
        ?string $userAgent
    ): ?string {
        if (!$userAgent) {
            return null;
        }

        return Str::limit(
            $userAgent,
            1000,
            ''
        );
    }
}