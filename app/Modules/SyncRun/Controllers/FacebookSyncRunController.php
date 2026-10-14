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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     * Supported filters:
     * - facebook_page_id
     * - status
     * - trigger
     * - type
     */
    public function index(
        ListFacebookSyncRunsRequest $request,
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $user = $request->user();

        Log::info(
            'Facebook sync runs list request started.',
            [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $user?->getKey(),
                'ip' => $request->ip(),
                'filters' => [
                    'facebook_page_id' => $request->input(
                        'facebook_page_id',
                    ),
                    'status' => $request->input('status'),
                    'trigger' => $request->input('trigger'),
                    'type' => $request->input('type'),
                    'per_page' => $request->perPage(),
                    'page' => $request->pageNumber(),
                ],
            ],
        );

        try {
            /*
             * -------------------------------------------------------------
             * Build query
             * -------------------------------------------------------------
             */

            $query = FacebookSyncRun::query()
                ->with([
                    'facebookPage',
                    'triggeredByUser',
                ])
                ->latest('created_at');

            /*
             * -------------------------------------------------------------
             * Facebook Page filter
             * -------------------------------------------------------------
             *
             * facebook_sync_runs.facebook_page_id contains the INTERNAL
             * Laravel UUID from facebook_pages.id.
             */
            if ($request->filled('facebook_page_id')) {
                $facebookPageId = (string) $request->input(
                    'facebook_page_id',
                );

                $query->where(
                    'facebook_page_id',
                    $facebookPageId,
                );
            }

            /*
             * -------------------------------------------------------------
             * Status filter
             * -------------------------------------------------------------
             */

            if ($request->filled('status')) {
                $query->where(
                    'status',
                    $request->input('status'),
                );
            }

            /*
             * -------------------------------------------------------------
             * Trigger filter
             * -------------------------------------------------------------
             */

            if ($request->filled('trigger')) {
                $query->where(
                    'trigger',
                    $request->input('trigger'),
                );
            }

            /*
             * -------------------------------------------------------------
             * Type filter
             * -------------------------------------------------------------
             */

            if ($request->filled('type')) {
                $query->where(
                    'type',
                    $request->input('type'),
                );
            }

            /*
             * -------------------------------------------------------------
             * Pagination
             * -------------------------------------------------------------
             */

            $runs = $query->paginate(
                perPage: $request->perPage(),
                page: $request->pageNumber(),
            );

            /*
             * -------------------------------------------------------------
             * Logging
             * -------------------------------------------------------------
             */

            Log::info(
                'Facebook sync runs list loaded successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user?->getKey(),
                    'total' => $runs->total(),
                    'current_page' => $runs->currentPage(),
                    'per_page' => $runs->perPage(),
                    'last_page' => $runs->lastPage(),
                    'returned_count' => $runs->count(),
                ],
            );

            /*
             * -------------------------------------------------------------
             * API Response
             * -------------------------------------------------------------
             */

            return ApiResponse::success(
                data: FacebookSyncRunResource::collection($runs),
                message: 'Facebook synchronization runs retrieved successfully.',
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to load Facebook sync runs list.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user?->getKey(),
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
                'user_id' => $user?->getKey(),
                'ip' => $request->ip(),
            ],
        );

        try {
            /*
             * -------------------------------------------------------------
             * Validate ID
             * -------------------------------------------------------------
             */

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            /*
             * -------------------------------------------------------------
             * Load synchronization run
             * -------------------------------------------------------------
             */

            $run = FacebookSyncRun::query()
                ->with([
                    'facebookPage',
                    'triggeredByUser',
                ])
                ->findOrFail($syncRunId);

            /*
             * -------------------------------------------------------------
             * Logging
             * -------------------------------------------------------------
             */

            Log::info(
                'Facebook sync run loaded successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $run->getKey(),
                    'user_id' => $user?->getKey(),
                    'facebook_page_id' => $run->facebook_page_id,
                    'status' => $run->status->value,
                    'type' => $run->type->value,
                    'trigger' => $run->trigger->value,
                    'queue' => $run->queue,
                    'attempts' => $run->attempts,
                    'is_active' => $run->isActive(),
                ],
            );

            /*
             * -------------------------------------------------------------
             * API Response
             * -------------------------------------------------------------
             */

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
                    'user_id' => $user?->getKey(),
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
                'user_id' => $user?->getKey(),
                'ip' => $request->ip(),
            ],
        );

        try {
            /*
             * -------------------------------------------------------------
             * Validate ID
             * -------------------------------------------------------------
             */

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            /*
             * -------------------------------------------------------------
             * Load run
             * -------------------------------------------------------------
             */

            $run = $this->syncRunService->find($syncRunId);

            $run->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            /*
             * -------------------------------------------------------------
             * Retry
             * -------------------------------------------------------------
             *
             * The service is responsible for:
             *
             * - validating the current status
             * - preventing duplicate active synchronization
             * - resetting/rebuilding required pipeline state
             * - dispatching the appropriate synchronization job
             * - returning the updated run
             */
            $retriedRun = $this->syncRunService->retry(
                $run,
                $user,
            );

            $retriedRun->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            /*
             * -------------------------------------------------------------
             * Logging
             * -------------------------------------------------------------
             */

            Log::info(
                'Facebook sync run retry queued successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $retriedRun->getKey(),
                    'user_id' => $user?->getKey(),
                    'previous_status' => $run->status->value,
                    'new_status' => $retriedRun->status->value,
                    'queue' => $retriedRun->queue,
                    'job_id' => $retriedRun->job_id,
                    'attempts' => $retriedRun->attempts,
                ],
            );

            /*
             * -------------------------------------------------------------
             * API Response
             * -------------------------------------------------------------
             */

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
                    'user_id' => $user?->getKey(),
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
                'user_id' => $user?->getKey(),
                'ip' => $request->ip(),
            ],
        );

        try {
            /*
             * -------------------------------------------------------------
             * Validate ID
             * -------------------------------------------------------------
             */

            $syncRunId = trim($id);

            if ($syncRunId === '') {
                return ApiResponse::notFound(
                    message: 'Synchronization run not found.',
                );
            }

            /*
             * -------------------------------------------------------------
             * Load run
             * -------------------------------------------------------------
             */

            $run = $this->syncRunService->find($syncRunId);

            $run->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            /*
             * -------------------------------------------------------------
             * Cancel synchronization
             * -------------------------------------------------------------
             *
             * The service is responsible for:
             *
             * - validating that the run is queued/running
             * - marking the run as cancelled
             * - preventing further pipeline work
             * - handling cancellation safely for queued jobs
             */
            $cancelledRun = $this->syncRunService->cancel(
                $run,
                $user,
            );

            $cancelledRun->load([
                'facebookPage',
                'triggeredByUser',
            ]);

            /*
             * -------------------------------------------------------------
             * Logging
             * -------------------------------------------------------------
             */

            Log::info(
                'Facebook sync run cancelled successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $cancelledRun->getKey(),
                    'user_id' => $user?->getKey(),
                    'previous_status' => $run->status->value,
                    'new_status' => $cancelledRun->status->value,
                    'queue' => $cancelledRun->queue,
                    'job_id' => $cancelledRun->job_id,
                ],
            );

            /*
             * -------------------------------------------------------------
             * API Response
             * -------------------------------------------------------------
             */

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
                    'user_id' => $user?->getKey(),
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
            '/([?&](?:access_token|appsecret_proof)=)[^&\s]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace(
            '/((?:access_token|appsecret_proof)[\'"]?\s*[:=]\s*)[^,\s}\'"]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        return mb_substr(
            $message,
            0,
            5000,
        );
    }
}