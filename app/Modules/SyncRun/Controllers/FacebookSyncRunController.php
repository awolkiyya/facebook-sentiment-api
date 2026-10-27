<?php

declare(strict_types=1);

namespace App\Modules\SyncRun\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FacebookSyncRun;
use App\Modules\SyncRun\Requests\ListFacebookSyncRunsRequest;
use App\Modules\SyncRun\Resources\FacebookSyncRunDetailResource;
use App\Modules\SyncRun\Resources\FacebookSyncRunResource;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use App\Services\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class FacebookSyncRunController extends Controller
{
    public function __construct(
        private readonly FacebookSyncRunService $syncRunService,
    ) {
    }

    /**
     * List Facebook synchronization runs.
     *
     * GET /api/v1/facebook/sync-runs
     *
     * SYSTEM_ADMIN:
     *   Can view all synchronization runs.
     *
     * Other authenticated users:
     *   Can view runs they triggered themselves.
     *
     * IMPORTANT:
     * This is a conservative ownership rule. If users should see
     * every run belonging to Pages they own or share, replace the
     * triggeredByUser scope with your actual FacebookPage access
     * relationship.
     */
    public function index(
        ListFacebookSyncRunsRequest $request,
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $user = $request->user();

        $validated = $request->validated();

        Log::info(
            'Facebook sync runs list request started.',
            [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $user?->getAuthIdentifier(),
                'ip' => $request->ip(),
                'filters' => [
                    'facebook_page_id' =>
                        $validated['facebook_page_id'] ?? null,
                    'status' => $validated['status'] ?? null,
                    'trigger' => $validated['trigger'] ?? null,
                    'type' => $validated['type'] ?? null,
                    'per_page' => $request->perPage(),
                    'page' => $request->pageNumber(),
                ],
            ],
        );

        try {
            $this->requireAuthenticatedUser($user);

            $isSystemAdmin = $this->isSystemAdmin($user);

            $query = FacebookSyncRun::query()
                ->with([
                    'facebookPage',
                    'triggeredByUser',
                ])
                ->latest('created_at');

            /*
             * Authorization scope.
             *
             * Non-admin users cannot retrieve the global list.
             * Scope the query before applying user-provided filters.
             */
            if (! $isSystemAdmin) {
                $query->whereHas(
                    'triggeredByUser',
                    static function ($userQuery) use ($user): void {
                        $userQuery->whereKey(
                            $user->getAuthIdentifier(),
                        );
                    },
                );
            }

            /*
             * Optional Facebook Page filter.
             *
             * This is the internal FacebookPage UUID, not the
             * external Meta Facebook Page ID.
             *
             * The filter narrows the authorized query; it does
             * not grant additional access.
             */
            if (! empty($validated['facebook_page_id'])) {
                $query->where(
                    'facebook_page_id',
                    $validated['facebook_page_id'],
                );
            }

            if (! empty($validated['status'])) {
                $query->where(
                    'status',
                    $validated['status'],
                );
            }

            if (! empty($validated['trigger'])) {
                $query->where(
                    'trigger',
                    $validated['trigger'],
                );
            }

            if (! empty($validated['type'])) {
                $query->where(
                    'type',
                    $validated['type'],
                );
            }

            $runs = $query->paginate(
                perPage: $request->perPage(),
                page: $request->pageNumber(),
            );

            Log::info(
                'Facebook sync runs list loaded successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->getAuthIdentifier(),
                    'is_system_admin' => $isSystemAdmin,
                    'total' => $runs->total(),
                    'current_page' => $runs->currentPage(),
                    'per_page' => $runs->perPage(),
                    'last_page' => $runs->lastPage(),
                    'returned_count' => $runs->count(),
                ],
            );

            return ApiResponse::success(
                data: FacebookSyncRunResource::collection($runs),
                message: 'Facebook synchronization runs retrieved successfully.',
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to load Facebook sync runs list.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user?->getAuthIdentifier(),
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeExceptionMessage(
                        $exception,
                    ),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Show a Facebook synchronization run.
     *
     * GET /api/v1/facebook/sync-runs/{id}
     *
     * SYSTEM_ADMIN can view any run.
     * Other users can view only runs they triggered.
     */
    public function show(
        Request $request,
        string $id,
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $user = $request->user();

        Log::info(
            'Facebook sync run detail request started.',
            [
                'request_id' => $requestId,
                'sync_run_id' => $id,
                'user_id' => $user?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ],
        );

        try {
            $this->requireAuthenticatedUser($user);

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            $run = FacebookSyncRun::query()
                ->with([
                    'facebookPage',
                    'triggeredByUser',
                ])
                ->findOrFail($syncRunId);

            /*
             * Authorize the specific run after loading it.
             * Never rely on possession of the run UUID.
             */
            $this->authorizeRunAccess($user, $run);

            Log::info(
                'Facebook sync run loaded successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $run->getKey(),
                    'user_id' => $user->getAuthIdentifier(),
                    'facebook_page_id' => $run->facebook_page_id,
                    'status' => $this->enumValue($run->status),
                    'type' => $this->enumValue($run->type),
                    'trigger' => $this->enumValue($run->trigger),
                    'queue' => $run->queue,
                    'attempts' => $run->attempts,
                    'is_active' => $run->isActive(),
                ],
            );

            return ApiResponse::success(
                data: new FacebookSyncRunDetailResource($run),
                message: 'Facebook synchronization run retrieved successfully.',
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to load Facebook sync run detail.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $id,
                    'user_id' => $user?->getAuthIdentifier(),
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeExceptionMessage(
                        $exception,
                    ),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Retry a failed or cancelled synchronization run.
     *
     * POST /api/v1/facebook/sync-runs/{id}/retry
     *
     * Access to the run and permission to retry are separate concerns.
     * This method enforces run access; the service must continue to
     * validate retryable statuses and prevent duplicate active runs.
     */
    public function retry(
        Request $request,
        string $id,
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $user = $request->user();

        Log::info(
            'Facebook sync run retry request started.',
            [
                'request_id' => $requestId,
                'sync_run_id' => $id,
                'user_id' => $user?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ],
        );

        try {
            $this->requireAuthenticatedUser($user);

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            $run = $this->syncRunService->find($syncRunId);

            $run->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            $this->authorizeRunAccess($user, $run);

            $previousStatus = $this->enumValue($run->status);

            $retriedRun = $this->syncRunService->retry(
                $run,
                $user,
            );

            $retriedRun->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            Log::info(
                'Facebook sync run retry queued successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $retriedRun->getKey(),
                    'user_id' => $user->getAuthIdentifier(),
                    'previous_status' => $previousStatus,
                    'new_status' => $this->enumValue(
                        $retriedRun->status,
                    ),
                    'queue' => $retriedRun->queue,
                    'job_id' => $retriedRun->job_id,
                    'attempts' => $retriedRun->attempts,
                ],
            );

            return ApiResponse::success(
                data: new FacebookSyncRunDetailResource($retriedRun),
                message: 'Facebook synchronization run has been queued for retry.',
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to retry Facebook sync run.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $id,
                    'user_id' => $user?->getAuthIdentifier(),
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeExceptionMessage(
                        $exception,
                    ),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Cancel a queued or running synchronization run.
     *
     * DELETE /api/v1/facebook/sync-runs/{id}
     *
     * Access to the run and permission to cancel are separate concerns.
     * The service must continue to validate cancellation state and
     * safely stop further pipeline work.
     */
    public function destroy(
        Request $request,
        string $id,
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $user = $request->user();

        Log::info(
            'Facebook sync run cancellation request started.',
            [
                'request_id' => $requestId,
                'sync_run_id' => $id,
                'user_id' => $user?->getAuthIdentifier(),
                'ip' => $request->ip(),
            ],
        );

        try {
            $this->requireAuthenticatedUser($user);

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            $run = $this->syncRunService->find($syncRunId);

            $run->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            $this->authorizeRunAccess($user, $run);

            $previousStatus = $this->enumValue($run->status);

            $cancelledRun = $this->syncRunService->cancel(
                $run,
                $user,
            );

            $cancelledRun->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            Log::info(
                'Facebook sync run cancelled successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $cancelledRun->getKey(),
                    'user_id' => $user->getAuthIdentifier(),
                    'previous_status' => $previousStatus,
                    'new_status' => $this->enumValue(
                        $cancelledRun->status,
                    ),
                    'queue' => $cancelledRun->queue,
                    'job_id' => $cancelledRun->job_id,
                ],
            );

            return ApiResponse::success(
                data: new FacebookSyncRunDetailResource($cancelledRun),
                message: 'Facebook synchronization run has been cancelled.',
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed while processing Facebook sync run cancellation request.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $id,
                    'user_id' => $user?->getAuthIdentifier(),
                    'exception_class' => $exception::class,
                    'exception_message' => $this->safeExceptionMessage(
                        $exception,
                    ),
                ],
            );

            throw $exception;
        }
    }

    /**
     * Ensure a request has an authenticated user.
     */
    private function requireAuthenticatedUser(
        ?Authenticatable $user,
    ): void {
        if ($user === null) {
            abort(401, 'Authentication is required.');
        }
    }

    /**
     * Determine whether the user has the SYSTEM_ADMIN role.
     *
     * This uses Spatie's hasRole() method when available.
     * Ensure the role's guard_name matches your application's
     * configured Spatie guard.
     */
    private function isSystemAdmin(
        ?Authenticatable $user,
    ): bool {
        if (
            $user === null
            || ! method_exists($user, 'hasRole')
        ) {
            return false;
        }

        return $user->hasRole('SYSTEM_ADMIN');
    }

    /**
     * Authorize access to a specific synchronization run.
     *
     * SYSTEM_ADMIN can access every run.
     * Other users can access only runs they triggered.
     *
     * The triggeredByUser relationship is used rather than assuming
     * a database foreign-key column name.
     */
    private function authorizeRunAccess(
        Authenticatable $user,
        FacebookSyncRun $run,
    ): void {
        if ($this->isSystemAdmin($user)) {
            return;
        }

        $triggeredByUser = $run->triggeredByUser;

        if (
            $triggeredByUser !== null
            && (string) $triggeredByUser->getAuthIdentifier()
                === (string) $user->getAuthIdentifier()
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to access this synchronization run.',
        );
    }

    /**
     * Resolve or generate a request ID.
     */
    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-ID');

        if (
            is_string($requestId)
            && trim($requestId) !== ''
        ) {
            return trim($requestId);
        }

        return (string) str()->uuid();
    }

    /**
     * Convert an enum or scalar value to a log-safe string.
     */
    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Sanitize exception messages before logging them.
     */
    private function safeExceptionMessage(
        Throwable $exception,
    ): string {
        $message = $exception->getMessage();

        if ($message === '') {
            return 'An unexpected error occurred.';
        }

        $message = preg_replace(
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/((?:access_token|appsecret_proof)[\'"]?\s*[:=]\s*)[^,\s}\'"]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr($message, 0, 5000);
    }
}
