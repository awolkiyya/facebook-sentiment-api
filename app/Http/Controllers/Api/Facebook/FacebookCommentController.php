<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Facebook;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListFacebookCommentsRequest;
use App\Http\Resources\FacebookCommentResource;
use App\Jobs\Facebook\SyncFacebookCommentsJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookSyncRun;
use App\Models\SystemSetting;
use App\Services\ApiResponse;
use App\Services\Facebook\FacebookCommentQueryService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FacebookCommentController extends Controller
{
      /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Maximum number of comments returned by one request.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default number of comments returned by one request.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Queue used for Facebook comment synchronization.
     */
    private const COMMENT_SYNC_QUEUE = 'facebook-comments';

    /**
     * Synchronization run type.
     */
    private const SYNC_RUN_TYPE = 'comments';


    /*
    |--------------------------------------------------------------------------
    | List Comments With AI Analysis
    |--------------------------------------------------------------------------
    */

    /**
     * List locally synchronized Facebook comments with their latest
     * persisted AI sentiment analysis.
     *
     * This endpoint does not call Meta or perform AI inference.
     *
     * Route:
     * GET /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}/comments
     *
     * @throws Throwable
     */
    public function index(
        ListFacebookCommentsRequest $request,
        FacebookCommentQueryService $commentQueryService,
    ): JsonResponse {
        $requestId = $this->requestId($request);

        $user = $request->user();

        $facebookPageId = trim(
            (string) $request->route('facebookPageId'),
        );

        $facebookPostId = trim(
            (string) $request->route('facebookPostId'),
        );

        /*
        |--------------------------------------------------------------------------
        | Request Logging
        |--------------------------------------------------------------------------
        */

        Log::info('Facebook comments index request started.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $user?->id,
            'facebook_page_id' => $facebookPageId,
            'facebook_post_id' => $facebookPostId,
            'ip' => $request->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

        if ($user === null) {
            Log::warning(
                'Facebook comments index rejected: unauthenticated.',
                [
                    'request_id' => $requestId,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'ip' => $request->ip(),
                ],
            );

            return ApiResponse::unauthorized(
                'Authentication is required.',
            );
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Validated Filters
            |--------------------------------------------------------------------------
            */

            $filters = $request->validated();

            $perPage = min(
                max(
                    (int) (
                        $filters['per_page']
                        ?? self::DEFAULT_PER_PAGE
                    ),
                    1,
                ),
                self::MAX_PER_PAGE,
            );

            $pageNumber = max(
                (int) ($filters['page'] ?? 1),
                1,
            );

            $search = trim(
                (string) ($filters['search'] ?? ''),
            );

            $sentiment = $filters['sentiment'] ?? null;
            $language = $filters['language'] ?? null;
            $analysisStatus = $filters['analysis_status'] ?? null;
            $sortBy = $filters['sort_by'] ?? 'newest';

            $startDate = $filters['start_date'] ?? null;
            $endDate = $filters['end_date'] ?? null;

            /*
            |--------------------------------------------------------------------------
            | Resolve Authorized Facebook Page
            |--------------------------------------------------------------------------
            */

            $facebookPage = $this->resolveUserPage(
                userId: (string) $user->id,
                facebookPageId: $facebookPageId,
                requestId: $requestId,
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Authorized Facebook Post
            |--------------------------------------------------------------------------
            |
            | The post must belong to the authorized page.
            | facebookPostId is the internal Laravel post identifier.
            |
            */

            $facebookPost = $this->resolvePost(
                page: $facebookPage,
                facebookPostId: $facebookPostId,
                requestId: $requestId,
            );

            /*
            |--------------------------------------------------------------------------
            | Query Comments Through Service
            |--------------------------------------------------------------------------
            |
            | The service owns:
            | - Search and analysis filters
            | - Date-range filtering
            | - Sorting
            | - Pagination
            |
            | Authorization remains in the controller.
            |
            */

            $comments = $commentQueryService->paginateForPost(
                post: $facebookPost,
                filters: $filters,
                perPage: $perPage,
                page: $pageNumber,
            );

            /*
            |--------------------------------------------------------------------------
            | Count Analyzed Comments On Current Page
            |--------------------------------------------------------------------------
            */

            $analyzedCount = collect($comments->items())
                ->filter(
                    static fn (FacebookComment $comment): bool =>
                        $comment->latestSentimentAnalysis !== null,
                )
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Serialize Resources
            |--------------------------------------------------------------------------
            */

            $commentData = collect($comments->items())
                ->map(
                    fn (FacebookComment $comment): array =>
                        FacebookCommentResource::make($comment)
                            ->resolve($request),
                )
                ->values()
                ->all();

            /*
            |--------------------------------------------------------------------------
            | Pagination Metadata
            |--------------------------------------------------------------------------
            */

            $pagination = [
                'current_page' => $comments->currentPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
                'last_page' => $comments->lastPage(),
                'from' => $comments->firstItem(),
                'to' => $comments->lastItem(),
                'has_more' => $comments->hasMorePages(),
            ];

            /*
            |--------------------------------------------------------------------------
            | Success Logging
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Facebook comments with AI analysis loaded successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,

                    'facebook_page_internal_id' => $facebookPage->id,
                    'facebook_page_id' => $facebookPage->facebook_page_id,

                    'facebook_post_internal_id' => $facebookPost->id,
                    'facebook_post_id' => $facebookPost->facebook_post_id,

                    'filters' => [
                        'search_applied' => $search !== '',
                        'sentiment' => $sentiment,
                        'language' => $language,
                        'analysis_status' => $analysisStatus,
                        'sort_by' => $sortBy,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],

                    'current_page' => $comments->currentPage(),
                    'per_page' => $comments->perPage(),
                    'total_comments' => $comments->total(),
                    'returned_count' => $comments->count(),
                    'analyzed_count' => $analyzedCount,
                    'unanalyzed_count' => max(
                        $comments->count() - $analyzedCount,
                        0,
                    ),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Success Response
            |--------------------------------------------------------------------------
            */

            return ApiResponse::success(
                data: $commentData,
                message: 'Facebook comments retrieved successfully.',
                meta: [
                    'pagination' => $pagination,
                ],
            );
        } catch (RuntimeException $exception) {
            Log::error(
                'Facebook comments index request failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            report($exception);

            return ApiResponse::serverError(
                'Failed to retrieve Facebook comments.',
            );
        } catch (Throwable $exception) {
            Log::critical(
                'Unexpected failure retrieving Facebook comments.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            report($exception);

            return ApiResponse::serverError(
                'Failed to retrieve Facebook comments.',
            );
        }
    }








    /*
    |--------------------------------------------------------------------------
    | Queue Comment Synchronization
    |--------------------------------------------------------------------------
    */

    /**
     * Queue Facebook comment synchronization for one post.
     *
     * POST:
     *
     * /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}/comments/sync
     *
     * Lifecycle:
     *
     * HTTP request:
     *
     *     queued
     *       ↓
     *     database commit
     *       ↓
     *     queue dispatch
     *
     * Queue worker:
     *
     *     queued
     *       ↓
     *     running
     *       ↓
     *     pipeline initialization
     *       ↓
     *     comment synchronization
     *       ↓
     *     AI processing
     *       ↓
     *     completed / failed
     *
     * The HTTP request NEVER marks the run as running.
     */
    public function sync(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $user = $request->user();

        $facebookPageId = trim(
            (string) $request->route('facebookPageId'),
        );

        $facebookPostId = trim(
            (string) $request->route('facebookPostId'),
        );

        Log::info('Facebook comments sync request started.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $user?->id,
            'facebook_page_id' => $facebookPageId,
            'facebook_post_id' => $facebookPostId,
            'ip' => $request->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

        if ($user === null) {
            Log::warning(
                'Facebook comments sync rejected: unauthenticated.',
                [
                    'request_id' => $requestId,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'ip' => $request->ip(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Page and Post
        |--------------------------------------------------------------------------
        */

        try {
            $facebookPage = $this->resolveUserPage(
                userId: (string) $user->id,
                facebookPageId: $facebookPageId,
                requestId: $requestId,
            );

            $facebookPost = $this->resolvePost(
                page: $facebookPage,
                facebookPostId: $facebookPostId,
                requestId: $requestId,
            );
        } catch (RuntimeException $exception) {
            Log::warning(
                'Facebook comments sync rejected while resolving resources.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (Throwable $exception) {
            Log::critical(
                'Facebook comments sync resource resolution failed unexpectedly.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' => $facebookPageId,
                    'facebook_post_id' => $facebookPostId,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to resolve the Facebook Page or post.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /*
        |--------------------------------------------------------------------------
        | System Settings
        |--------------------------------------------------------------------------
        */

        try {
            $settings = $this->getSystemSettings(
                requestId: $requestId,
            );
        } catch (RuntimeException $exception) {
            Log::critical(
                'Facebook comments synchronization blocked: system settings unavailable.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'System settings are not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Integration Switch
        |--------------------------------------------------------------------------
        */

        if (!$settings->facebook_sync_enabled) {
            Log::warning(
                'Facebook comments sync blocked: Facebook integration is disabled.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Facebook data integration is currently disabled.',
            ], Response::HTTP_CONFLICT);
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Comments Switch
        |--------------------------------------------------------------------------
        */

        if (!$settings->facebook_comments_enabled) {
            Log::warning(
                'Facebook comments sync blocked: comment synchronization is disabled.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Facebook comment synchronization is currently disabled.',
            ], Response::HTTP_CONFLICT);
        }

        /*
        |--------------------------------------------------------------------------
        | Page Monitoring
        |--------------------------------------------------------------------------
        */

        if (!$facebookPage->is_active) {
            Log::warning(
                'Facebook comments sync blocked: Page monitoring is disabled.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_internal_id' => $facebookPage->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Facebook Page monitoring is disabled. Enable monitoring before synchronizing comments.',
            ], Response::HTTP_CONFLICT);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Sync Run
        |--------------------------------------------------------------------------
        */

        try {
            $result = app(DatabaseManager::class)->transaction(
                function () use (
                    $facebookPage,
                    $facebookPost,
                    $user,
                    $requestId,
                ): array {
                    /*
                    |--------------------------------------------------------------------------
                    | Lock Post
                    |--------------------------------------------------------------------------
                    |
                    | This serializes concurrent comment synchronization
                    | requests targeting the same post.
                    |
                    */

                    $lockedPost = FacebookPost::query()
                        ->whereKey($facebookPost->id)
                        ->where(
                            'facebook_page_id',
                            $facebookPage->id,
                        )
                        ->lockForUpdate()
                        ->first();

                    if ($lockedPost === null) {
                        throw new RuntimeException(
                            'Facebook post was not found for the specified Page.',
                        );
                    }

                    Log::debug(
                        'Facebook post locked for comment synchronization.',
                        [
                            'request_id' => $requestId,
                            'facebook_page_internal_id' =>
                                $facebookPage->id,
                            'facebook_page_id' =>
                                $facebookPage->facebook_page_id,
                            'facebook_post_internal_id' =>
                                $lockedPost->id,
                            'facebook_post_id' =>
                                $lockedPost->facebook_post_id,
                        ],
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Check Existing Active Comment Sync
                    |--------------------------------------------------------------------------
                    |
                    | Only queued/running comment syncs for this exact
                    | Page + Post combination block another request.
                    |
                    */

                    $existingRun = FacebookSyncRun::query()
                        ->where(
                            'facebook_page_id',
                            $facebookPage->id,
                        )
                        ->where(
                            'type',
                            self::SYNC_RUN_TYPE,
                        )
                        ->whereIn(
                            'status',
                            [
                                'queued',
                                'running',
                            ],
                        )
                        ->where(
                            'metadata->facebook_post_id',
                            (string) $lockedPost->id,
                        )
                        ->latest('created_at')
                        ->first();

                    if ($existingRun !== null) {
                        Log::info(
                            'Active Facebook comment synchronization already exists.',
                            [
                                'request_id' => $requestId,
                                'user_id' => $user->id,
                                'sync_run_id' => $existingRun->id,
                                'facebook_page_internal_id' =>
                                    $facebookPage->id,
                                'facebook_page_id' =>
                                    $facebookPage->facebook_page_id,
                                'facebook_post_internal_id' =>
                                    $lockedPost->id,
                                'facebook_post_id' =>
                                    $lockedPost->facebook_post_id,
                                'status' => $existingRun->status,
                                'queue' => $existingRun->queue,
                            ],
                        );

                        return [
                            'sync_run' => $existingRun,
                            'created' => false,
                        ];
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Create Queued Sync Run
                    |--------------------------------------------------------------------------
                    |
                    | IMPORTANT:
                    |
                    | The run remains QUEUED here.
                    |
                    | The queue worker is responsible for changing it
                    | to RUNNING after the job actually starts.
                    |
                    */

                    $syncRun = FacebookSyncRun::query()->create([
                        'facebook_page_id' =>
                            $facebookPage->id,

                        'triggered_by_user_id' =>
                            $user->id,

                        'trigger' =>
                            'manual',

                        'type' =>
                            self::SYNC_RUN_TYPE,

                        'status' =>
                            'queued',

                        'queue' =>
                            self::COMMENT_SYNC_QUEUE,

                        'attempts' =>
                            0,

                        'queued_at' =>
                            now(),

                        'metadata' => [
                            'request_id' =>
                                $requestId,

                            /*
                             * Internal Laravel FacebookPost UUID.
                             */
                            'facebook_post_id' =>
                                (string) $lockedPost->id,

                            /*
                             * External Meta/Facebook Post ID.
                             */
                            'facebook_post_external_id' =>
                                (string) $lockedPost->facebook_post_id,

                            'source' =>
                                'facebook_comment_sync_endpoint',

                            /*
                             * Pipeline has not started yet.
                             *
                             * It will be initialized by the queue
                             * worker after the SyncRun becomes RUNNING.
                             */
                            'pipeline' => [
                                'initialized' => false,
                                'completed' => false,
                            ],
                        ],
                    ]);

                    Log::info(
                        'Facebook comment synchronization run created successfully.',
                        [
                            'request_id' => $requestId,
                            'user_id' => $user->id,
                            'sync_run_id' => $syncRun->id,
                            'facebook_page_internal_id' =>
                                $facebookPage->id,
                            'facebook_page_id' =>
                                $facebookPage->facebook_page_id,
                            'facebook_post_internal_id' =>
                                $lockedPost->id,
                            'facebook_post_id' =>
                                $lockedPost->facebook_post_id,
                            'status' => $syncRun->status,
                            'queue' => $syncRun->queue,
                            'attempts' => $syncRun->attempts,
                        ],
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | IMPORTANT
                    |--------------------------------------------------------------------------
                    |
                    | Pipeline initialization is intentionally NOT called
                    | here.
                    |
                    | Your current FacebookSyncRunService rejects pipeline
                    | initialization while the SyncRun is QUEUED.
                    |
                    | The queue worker must initialize the pipeline after
                    | changing the SyncRun to RUNNING.
                    |
                    */

                    return [
                        'sync_run' => $syncRun,
                        'created' => true,
                    ];
                },
            );
        } catch (Throwable $exception) {
            Log::error(
                'Facebook comment synchronization run creation failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_internal_id' =>
                        $facebookPage->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_internal_id' =>
                        $facebookPost->id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to create Facebook comment synchronization.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /*
        |--------------------------------------------------------------------------
        | Extract Sync Run
        |--------------------------------------------------------------------------
        */

        /** @var FacebookSyncRun $syncRun */
        $syncRun = $result['sync_run'];

        /*
        |--------------------------------------------------------------------------
        | Existing Active Run
        |--------------------------------------------------------------------------
        */

        if ($result['created'] === false) {
            Log::info(
                'Returning existing active Facebook comment synchronization.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'sync_run_id' => $syncRun->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'status' => $syncRun->status,
                    'queue' => $syncRun->queue,
                ],
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Facebook comment synchronization is already queued or running.',

                'data' => [
                    'sync_run_id' =>
                        $syncRun->id,

                    'post_id' =>
                        $facebookPost->id,

                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,

                    'status' =>
                        $syncRun->status,

                    'type' =>
                        $syncRun->type,

                    'trigger' =>
                        $syncRun->trigger,

                    'queue' =>
                        $syncRun->queue,
                ],
            ], Response::HTTP_ACCEPTED);
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatch Queue Job
        |--------------------------------------------------------------------------
        */

        try {
            Log::info(
                'Dispatching Facebook comment synchronization job.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'sync_run_id' => $syncRun->id,
                    'facebook_page_internal_id' =>
                        $facebookPage->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_internal_id' =>
                        $facebookPost->id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'queue' =>
                        self::COMMENT_SYNC_QUEUE,
                ],
            );

            SyncFacebookCommentsJob::dispatch(
                (string) $facebookPost->id,
                (string) $syncRun->id,
            )->onQueue(self::COMMENT_SYNC_QUEUE);

            Log::info(
                'Facebook comment synchronization job dispatched successfully.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'sync_run_id' => $syncRun->id,
                    'facebook_page_internal_id' =>
                        $facebookPage->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_internal_id' =>
                        $facebookPost->id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'queue' =>
                        self::COMMENT_SYNC_QUEUE,
                    'status' =>
                        $syncRun->status,
                ],
            );
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Queue Dispatch Failure
            |--------------------------------------------------------------------------
            |
            | The database transaction has already committed.
            |
            | Therefore the SyncRun must not remain QUEUED forever.
            |
            */

            Log::critical(
                'Facebook comment synchronization job dispatch failed.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'sync_run_id' => $syncRun->id,
                    'facebook_page_id' =>
                        $facebookPage->facebook_page_id,
                    'facebook_post_id' =>
                        $facebookPost->facebook_post_id,
                    'queue' =>
                        self::COMMENT_SYNC_QUEUE,
                    'status' =>
                        $syncRun->status,
                    'exception_class' => $exception::class,
                    'exception_message' =>
                        $this->safeExceptionMessage($exception),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Mark SyncRun Failed
            |--------------------------------------------------------------------------
            */

            try {
                $syncRunService = app(
                    \App\Modules\SyncRun\Services\FacebookSyncRunService::class,
                );

                $syncRunService->markFailed(
                    runId: (string) $syncRun->id,
                    errorMessage:
                        'Facebook comment synchronization job could not be queued.',
                );

                Log::warning(
                    'Facebook comment SyncRun marked failed after queue dispatch failure.',
                    [
                        'request_id' => $requestId,
                        'sync_run_id' => $syncRun->id,
                    ],
                );
            } catch (Throwable $markFailedException) {
                Log::critical(
                    'Failed to mark Facebook comment SyncRun as failed after queue dispatch failure.',
                    [
                        'request_id' => $requestId,
                        'sync_run_id' => $syncRun->id,
                        'exception_class' =>
                            $markFailedException::class,
                        'exception_message' =>
                            $this->safeExceptionMessage(
                                $markFailedException,
                            ),
                    ],
                );

                report($markFailedException);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    'Facebook comment synchronization could not be queued.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /*
        |--------------------------------------------------------------------------
        | HTTP 202
        |--------------------------------------------------------------------------
        */

        Log::info(
            'Facebook comments synchronization request accepted.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'sync_run_id' => $syncRun->id,
                'facebook_page_internal_id' =>
                    $facebookPage->id,
                'facebook_page_id' =>
                    $facebookPage->facebook_page_id,
                'facebook_post_internal_id' =>
                    $facebookPost->id,
                'facebook_post_id' =>
                    $facebookPost->facebook_post_id,
                'status' =>
                    $syncRun->status,
                'queue' =>
                    $syncRun->queue,
            ],
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Facebook comment synchronization has been queued.',

            'data' => [
                'sync_run_id' =>
                    $syncRun->id,

                'post_id' =>
                    $facebookPost->id,

                'facebook_post_id' =>
                    $facebookPost->facebook_post_id,

                'status' =>
                    $syncRun->status,

                'type' =>
                    $syncRun->type,

                'trigger' =>
                    $syncRun->trigger,

                'queue' =>
                    $syncRun->queue,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Facebook Page
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a Facebook Page belonging to the authenticated user.
     *
     * Incoming identifier:
     *
     *     facebook_pages.facebook_page_id
     *
     * Internal identifier:
     *
     *     facebook_pages.id
     *
     * @throws RuntimeException
     */
    private function resolveUserPage(
        string $userId,
        string $facebookPageId,
        ?string $requestId = null,
    ): FacebookPage {
        Log::debug('Facebook Page lookup started.', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'facebook_page_id_parameter' => $facebookPageId,
        ]);

        $page = FacebookPage::query()
            ->where(
                'facebook_page_id',
                $facebookPageId,
            )
            ->where(
                'connected_by_user_id',
                $userId,
            )
            ->first();

        if ($page === null) {
            Log::warning(
                'Facebook Page lookup returned no result.',
                [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'facebook_page_id_parameter' => $facebookPageId,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Diagnostic Lookup
            |--------------------------------------------------------------------------
            |
            | Only record whether the Meta Page ID exists somewhere.
            |
            | Do not expose another user's internal identifiers.
            |
            */

            $existsAnywhere = FacebookPage::query()
                ->where(
                    'facebook_page_id',
                    $facebookPageId,
                )
                ->exists();

            Log::debug(
                'Facebook Page cross-user diagnostic lookup completed.',
                [
                    'request_id' => $requestId,
                    'requested_user_id' => $userId,
                    'facebook_page_id_parameter' => $facebookPageId,
                    'page_exists_in_system' => $existsAnywhere,
                ],
            );

            throw new RuntimeException(
                'Facebook Page not found.',
            );
        }

        Log::debug('Facebook Page lookup successful.', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'page_internal_id' => $page->id,
            'facebook_page_id' => $page->facebook_page_id,
            'page_name' => $page->name,
            'is_active' => $page->is_active,
        ]);

        return $page;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Facebook Post
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a Facebook Post belonging to the supplied Page.
     *
     * The incoming identifier is the internal Laravel UUID.
     *
     * @throws RuntimeException
     */
    private function resolvePost(
        FacebookPage $page,
        string $facebookPostId,
        ?string $requestId = null,
    ): FacebookPost {
        Log::debug('Facebook Post lookup started.', [
            'request_id' => $requestId,
            'page_internal_id' => $page->id,
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_internal_id' => $facebookPostId,
        ]);

        $post = FacebookPost::query()
            ->whereKey($facebookPostId)
            ->where(
                'facebook_page_id',
                $page->id,
            )
            ->first();

        if ($post === null) {
            Log::warning(
                'Facebook Post lookup returned no result for Page.',
                [
                    'request_id' => $requestId,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'facebook_post_internal_id' => $facebookPostId,
                ],
            );

            throw new RuntimeException(
                'The Facebook post does not belong to the specified Facebook page.',
            );
        }

        Log::debug('Facebook Post lookup successful.', [
            'request_id' => $requestId,
            'page_internal_id' => $page->id,
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_internal_id' => $post->id,
            'facebook_post_id' => $post->facebook_post_id,
        ]);

        return $post;
    }

    /*
    |--------------------------------------------------------------------------
    | System Settings
    |--------------------------------------------------------------------------
    */

    /**
     * Get global application settings.
     *
     * Fails closed when no settings record exists.
     *
     * @throws RuntimeException
     */
    private function getSystemSettings(
        ?string $requestId = null,
    ): SystemSetting {
        Log::debug(
            'Loading system settings for Facebook comments request.',
            [
                'request_id' => $requestId,
            ],
        );

        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            Log::critical(
                'System settings record was not found while processing Facebook comments.',
                [
                    'request_id' => $requestId,
                ],
            );

            throw new RuntimeException(
                'System settings are not configured.',
            );
        }

        Log::debug(
            'System settings loaded successfully.',
            [
                'request_id' => $requestId,
                'facebook_sync_enabled' =>
                    (bool) $settings->facebook_sync_enabled,
                'facebook_comments_enabled' =>
                    (bool) $settings->facebook_comments_enabled,
            ],
        );

        return $settings;
    }

    /*
    |--------------------------------------------------------------------------
    | Request Correlation
    |--------------------------------------------------------------------------
    */

    /**
     * Get the request correlation ID.
     *
     * Reuses a valid X-Request-ID supplied by the client or generates
     * a UUID when one is not supplied.
     */
    private function requestId(Request $request): string
    {
        $existingRequestId = $request->header(
            'X-Request-ID',
        );

        if (
            is_string($existingRequestId)
            && trim($existingRequestId) !== ''
            && strlen($existingRequestId) <= 100
        ) {
            return trim($existingRequestId);
        }

        return (string) Str::uuid();
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Sanitization
    |--------------------------------------------------------------------------
    */

    /**
     * Return a safe exception message for logs.
     *
     * Sensitive Facebook query parameters are removed.
     */
    private function safeExceptionMessage(
        Throwable $exception,
    ): string {
        $message = trim(
            $exception->getMessage(),
        );

        if ($message === '') {
            return $exception::class;
        }

        /*
        |--------------------------------------------------------------------------
        | Facebook Access Token
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/([?&](?:access_token)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Facebook App Secret Proof
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/([?&](?:appsecret_proof)=)[^&\s]*/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Authorization Header
        |--------------------------------------------------------------------------
        */

        $message = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        /*
        |--------------------------------------------------------------------------
        | Limit Message Size
        |--------------------------------------------------------------------------
        */

        return mb_substr(
            $message,
            0,
            5000,
        );
    }
}