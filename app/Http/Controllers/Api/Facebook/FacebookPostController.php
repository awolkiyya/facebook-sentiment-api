<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Facebook;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacebookPostResource;
use App\Jobs\Facebook\SyncFacebookPostsJob;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookSyncRun;
use App\Models\SystemSetting;
use App\Modules\SyncRun\Services\FacebookSyncRunService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use App\Enums\FacebookSyncTrigger;
use App\Enums\FacebookSyncType;
use Illuminate\Validation\Rule;
use App\Services\ApiResponse;
use App\Models\FacebookComment;


final class FacebookPostController extends Controller
{
    /**
 * Get locally synchronized Facebook Page posts.
 *
 * GET /api/v1/facebook/pages/{facebookPage}/posts
 *
 * Query parameters:
 * - search: Search post message text.
 * - sort_by: published_at | engagement
 * - sort_direction: asc | desc
 * - per_page: 1-100
 * - page: Current page number
 *
 * IMPORTANT:
 * - Does not call Meta Graph API.
 * - $facebookPage is the external Meta/Facebook Page ID.
 * - facebook_posts.facebook_page_id references facebook_pages.id.
 */




 public function index(
    Request $request,
    string $facebookPage,
): JsonResponse {
    $requestId = $this->requestId($request);
    $user = $request->user();

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    if ($user === null) {
        return ApiResponse::unauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Query Parameters
    |--------------------------------------------------------------------------
    */

    $validated = $request->validate([
        'search' => [
            'sometimes',
            'nullable',
            'string',
            'max:255',
        ],
        'sort_by' => [
            'sometimes',
            'string',
            Rule::in([
                'published_at',
                'engagement',
            ]),
        ],
        'sort_direction' => [
            'sometimes',
            'string',
            Rule::in([
                'asc',
                'desc',
            ]),
        ],
        'per_page' => [
            'sometimes',
            'integer',
            'min:1',
            'max:100',
        ],
        'page' => [
            'sometimes',
            'integer',
            'min:1',
        ],
    ]);

    $search = trim((string) ($validated['search'] ?? ''));
    $sortBy = $validated['sort_by'] ?? 'published_at';
    $sortDirection = $validated['sort_direction'] ?? 'desc';
    $perPage = (int) ($validated['per_page'] ?? 20);
    $pageNumber = (int) ($validated['page'] ?? 1);

    try {
        /*
        |--------------------------------------------------------------------------
        | Resolve Accessible Facebook Page
        |--------------------------------------------------------------------------
        |
        | $facebookPage is the external Meta Page ID.
        | facebook_posts.facebook_page_id references facebook_pages.id.
        |
        */

        $page = $this->resolveUserPage(
            userId: (string) $user->id,
            facebookPageId: $facebookPage,
            requestId: $requestId,
        );

        /*
        |--------------------------------------------------------------------------
        | Base Query
        |--------------------------------------------------------------------------
        */

        $query = FacebookPost::query()
            ->where(
                'facebook_posts.facebook_page_id',
                $page->id,
            )
            ->with([
                'attachments.children',
                'comments',
            ])
            ->withCount('comments');

        /*
        |--------------------------------------------------------------------------
        | Server-Side Search
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {
            $query->where(
                'facebook_posts.message',
                'ILIKE',
                '%' . $search . '%',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Summary Across All Matching Posts
        |--------------------------------------------------------------------------
        |
        | Calculate the summary before pagination.
        | Use the stored post-level engagement counts.
        |
        */

        $summaryQuery = clone $query;

        $totalPosts = (int) (
            (clone $summaryQuery)->count('facebook_posts.id')
        );

        $totalLikes = (int) (
            (clone $summaryQuery)->sum('facebook_posts.like_count')
        );

        $totalComments = (int) (
            (clone $summaryQuery)->sum('facebook_posts.comment_count')
        );

        $totalShares = (int) (
            (clone $summaryQuery)->sum('facebook_posts.share_count')
        );

        $summary = [
            'total_posts' => $totalPosts,
            'total_likes' => $totalLikes,
            'total_comments' => $totalComments,
            'total_shares' => $totalShares,
        ];

        /*
        |--------------------------------------------------------------------------
        | Server-Side Sorting
        |--------------------------------------------------------------------------
        */

        if ($sortBy === 'engagement') {
            $query->orderByRaw(
                '(
                    COALESCE(facebook_posts.like_count, 0)
                    + COALESCE(facebook_posts.comment_count, 0)
                    + COALESCE(facebook_posts.share_count, 0)
                ) ' . strtoupper($sortDirection)
            );
        } else {
            $query->orderByRaw(
                'facebook_posts.published_at '
                . strtoupper($sortDirection)
                . ' NULLS LAST'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Stable Secondary Ordering
        |--------------------------------------------------------------------------
        */

        $query->orderByDesc('facebook_posts.created_at')
            ->orderByDesc('facebook_posts.id');

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $posts = $query->paginate(
            perPage: $perPage,
            page: $pageNumber,
        );

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        */

        Log::info('Facebook posts loaded successfully.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'page_internal_id' => $page->id,
            'facebook_page_id' => $facebookPage,
            'search_applied' => $search !== '',
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
            'current_page' => $posts->currentPage(),
            'per_page' => $posts->perPage(),
            'total' => $posts->total(),
            'summary' => $summary,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data: FacebookPostResource::collection(
                $posts->getCollection(),
            )->resolve($request),
            message: 'Facebook posts retrieved successfully.',
            meta: [
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                    'last_page' => $posts->lastPage(),
                    'from' => $posts->firstItem(),
                    'to' => $posts->lastItem(),
                    'has_more' => $posts->hasMorePages(),
                ],
                'filters' => [
                    'search' => $search !== '' ? $search : null,
                    'sort_by' => $sortBy,
                    'sort_direction' => $sortDirection,
                ],
            ],
            summary: $summary,
        );
    } catch (ModelNotFoundException $exception) {
        Log::warning(
            'Facebook posts page not found for authenticated user.',
            [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'facebook_page_id' => $facebookPage,
            ],
        );

        throw $exception;
    } catch (Throwable $exception) {
        Log::error('Facebook posts index failed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'facebook_page_id' => $facebookPage,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);

        throw $exception;
    }
}






/**
 * Get one locally synchronized Facebook Page post.
 *
 * GET /api/v1/facebook/pages/{facebookPage}/posts/{post}
 *
 * IMPORTANT:
 * - $facebookPage is the external Meta/Facebook Page ID.
 * - $post is the internal FacebookPost UUID.
 * - The post must belong to the resolved page.
 * - The authenticated user must have access to that page.
 * - Does not call the Meta Graph API.
 */
public function show(
    Request $request,
    string $facebookPage,
    string $post,
): JsonResponse {
    $requestId = $this->requestId($request);
    $user = $request->user();

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    if ($user === null) {
        return ApiResponse::unauthorized();
    }

    try {
        /*
        |--------------------------------------------------------------------------
        | Resolve Accessible Facebook Page
        |--------------------------------------------------------------------------
        */

        $page = $this->resolveUserPage(
            userId: (string) $user->id,
            facebookPageId: $facebookPage,
            requestId: $requestId,
        );

        /*
        |--------------------------------------------------------------------------
        | Retrieve Specific Post
        |--------------------------------------------------------------------------
        |
        | facebookPage is the external Meta Page ID.
        | facebook_posts.facebook_page_id references facebook_pages.id.
        | post is the local FacebookPost primary key.
        |
        */

        $facebookPost = FacebookPost::query()
            ->where(
                'facebook_posts.facebook_page_id',
                $page->id,
            )
            ->where(
                'facebook_posts.id',
                $post,
            )
            ->with([
                'attachments.children',
                'comments',
            ])
            ->withCount('comments')
            ->first();

        if ($facebookPost === null) {
            Log::notice('Facebook post not found for accessible page.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'page_internal_id' => $page->id,
                'facebook_page_id' => $facebookPage,
                'post_id' => $post,
            ]);

            return ApiResponse::notFound(
                message: 'Facebook post not found.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Success Logging
        |--------------------------------------------------------------------------
        */

        Log::info('Facebook post retrieved successfully.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'page_internal_id' => $page->id,
            'facebook_page_id' => $facebookPage,
            'post_id' => $facebookPost->getKey(),
            'comments_count' => $facebookPost->comments_count,
        ]);

        /*
        |--------------------------------------------------------------------------
        | API Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data:  (new FacebookPostResource($facebookPost))
                    ->resolve($request),
            message: 'Facebook post retrieved successfully.',
        );
    } catch (ModelNotFoundException $exception) {
        Log::warning('Facebook page not found for authenticated user.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'facebook_page_id' => $facebookPage,
            'post_id' => $post,
        ]);

        throw $exception;
    } catch (Throwable $exception) {
        Log::error('Facebook post retrieval failed.', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'facebook_page_id' => $facebookPage,
            'post_id' => $post,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);

        throw $exception;
    }
}


    /**
     * Queue Facebook Page post synchronization.
     *
     * POST /api/v1/facebook/pages/{facebookPage}/posts/sync
     *
     * IMPORTANT:
     * - $facebookPage is the external Meta Page ID.
     * - The queue job receives the local FacebookPage UUID.
     * - This endpoint never calls Meta directly.
     * - facebook_auto_sync is intentionally not checked here.
     */
    public function sync(
        Request $request,
        string $facebookPage,
    ): JsonResponse {
        $requestId = $this->requestId($request);

        Log::info('Facebook posts sync request started.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'facebook_page_id_parameter' => $facebookPage,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

        $user = $request->user();

        if ($user === null) {
            Log::warning(
                'Facebook posts sync request rejected: unauthenticated.',
                [
                    'request_id' => $requestId,
                    'facebook_page_id_parameter' => $facebookPage,
                    'ip' => $request->ip(),
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $syncRun = null;

        try {
            /*
            |--------------------------------------------------------------------------
            | Global Settings
            |--------------------------------------------------------------------------
            */

            $settings = $this->getSystemSettings(
                requestId: $requestId,
            );

            /*
            |--------------------------------------------------------------------------
            | Master Facebook Switch
            |--------------------------------------------------------------------------
            */

            if (!$settings->facebook_sync_enabled) {
                Log::warning(
                    'Facebook posts sync blocked: Facebook integration is disabled.',
                    [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'facebook_page_id_parameter' => $facebookPage,
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
            | Facebook Posts Switch
            |--------------------------------------------------------------------------
            */

            if (!$settings->facebook_posts_enabled) {
                Log::warning(
                    'Facebook posts sync blocked: post synchronization is disabled.',
                    [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'facebook_page_id_parameter' => $facebookPage,
                    ],
                );

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Facebook post synchronization is currently disabled.',
                ], Response::HTTP_CONFLICT);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Facebook Page
            |--------------------------------------------------------------------------
            */

            $page = $this->resolveUserPage(
                userId: (string) $user->id,
                facebookPageId: $facebookPage,
                requestId: $requestId,
            );

            Log::info(
                'Facebook Page resolved successfully for posts sync.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'page_name' => $page->name,
                    'is_active' => $page->is_active,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Page Monitoring Check
            |--------------------------------------------------------------------------
            */

            if (!$page->is_active) {
                Log::warning(
                    'Facebook posts sync blocked: Page monitoring is disabled.',
                    [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'page_internal_id' => $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                    ],
                );

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Facebook Page monitoring is disabled. Enable monitoring before synchronizing posts.',
                ], Response::HTTP_CONFLICT);
            }

            /*
            |--------------------------------------------------------------------------
            | Create Sync Run
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Use FacebookSyncRunService rather than directly calling
            | FacebookSyncRun::create().
            |
            | This keeps:
            |
            | - duplicate active-run protection
            | - transaction handling
            | - metadata initialization
            | - validation
            |
            | centralized in one service.
            |
            */

            $syncRunService = app(FacebookSyncRunService::class);

            $syncRun = $syncRunService->create(
                facebookPageId: (string) $page->id,
                type: FacebookSyncType::POSTS,
                trigger: FacebookSyncTrigger::MANUAL,
                triggeredByUserId: (string) $user->id,
                queue: 'facebook',
                metadata: [
                    'request_id' => $requestId,
            
                    'source' => 'facebook_posts_sync_endpoint',
            
                    'requested_facebook_page_id' => (string) $facebookPage,
            
                    'requested_at' => now()->toIso8601String(),
            
                    'pipeline' => [
                        'initialized' => false,
                        'completed' => false,
                    ],
                ],
            );

            Log::info(
                'Facebook synchronization run created through SyncRunService.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $syncRun->id,
                    'user_id' => $user->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'trigger' => $syncRun->trigger,
                    'type' => $syncRun->type,
                    'status' => $syncRun->status,
                    'queue' => $syncRun->queue,
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Dispatch Root Synchronization Job
            |--------------------------------------------------------------------------
            |
            | Only identifiers are passed.
            |
            | We deliberately pass:
            |
            |     facebook_pages.id
            |
            | NOT:
            |
            |     facebook_pages.facebook_page_id
            |
            | The Job will reload the Page and its encrypted access token
            | from the database.
            |
            */

            SyncFacebookPostsJob::dispatch(
                facebookPageUuid: (string) $page->id,
                syncRunId: (string) $syncRun->id,
            )->onQueue('facebook');

            Log::info(
                'Facebook post synchronization root job dispatched successfully.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $syncRun->id,
                    'user_id' => $user->id,
                    'page_internal_id' => $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'queue' => 'facebook',
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'message' =>
                    'Facebook Page post synchronization has been queued.',

                'data' => [
                    'sync_run_id' => $syncRun->id,

                    /*
                    | External Meta Page ID is intentionally returned
                    | because this is the API Page identifier.
                    */
                    'page_id' => $page->facebook_page_id,

                    'status' => $syncRun->status,

                    'type' => $syncRun->type,

                    'trigger' => $syncRun->trigger,

                    'queue' => $syncRun->queue,
                ],
            ], Response::HTTP_ACCEPTED);
        } catch (ModelNotFoundException $exception) {
            Log::warning(
                'Facebook posts sync queueing failed: Page not found.',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'facebook_page_id_parameter' => $facebookPage,
                    'exception_class' => $exception::class,
                ],
            );

            throw $exception;
        } catch (RuntimeException $exception) {
            Log::error(
                'Facebook posts sync queueing failed with RuntimeException.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $syncRun?->id,
                    'user_id' => $user->id,
                    'facebook_page_id_parameter' => $facebookPage,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                ],
            );

            /*
            |--------------------------------------------------------------------------
            | If the SyncRun was created but queueing failed, do not leave
            | it permanently stuck in QUEUED.
            |--------------------------------------------------------------------------
            */

            if ($syncRun !== null) {
                try {
                    app(FacebookSyncRunService::class)->markFailed(
                        runId: (string) $syncRun->id,
                        errorMessage: 'Synchronization could not be queued.',
                    );

                    Log::warning(
                        'Facebook SyncRun marked failed after queue dispatch RuntimeException.',
                        [
                            'request_id' => $requestId,
                            'sync_run_id' => $syncRun->id,
                        ],
                    );
                } catch (Throwable $markFailedException) {
                    Log::critical(
                        'Failed to mark Facebook SyncRun as failed after queue dispatch error.',
                        [
                            'request_id' => $requestId,
                            'sync_run_id' => $syncRun->id,
                            'exception_class' => $markFailedException::class,
                            'exception_message' =>
                                $markFailedException->getMessage(),
                        ],
                    );
                }
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    'Facebook Page synchronization could not be queued.',
            ], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            Log::critical(
                'Facebook posts synchronization queueing failed unexpectedly.',
                [
                    'request_id' => $requestId,
                    'sync_run_id' => $syncRun?->id,
                    'user_id' => $user->id,
                    'facebook_page_id_parameter' => $facebookPage,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                ],
            );

            if ($syncRun !== null) {
                try {
                    app(FacebookSyncRunService::class)->markFailed(
                        runId: (string) $syncRun->id,
                        errorMessage: 'Unexpected error while queuing synchronization.',
                    );

                    Log::warning(
                        'Facebook SyncRun marked failed after unexpected queueing error.',
                        [
                            'request_id' => $requestId,
                            'sync_run_id' => $syncRun->id,
                        ],
                    );
                } catch (Throwable $markFailedException) {
                    Log::critical(
                        'Failed to mark Facebook SyncRun as failed after unexpected queueing error.',
                        [
                            'request_id' => $requestId,
                            'sync_run_id' => $syncRun->id,
                            'exception_class' => $markFailedException::class,
                            'exception_message' =>
                                $markFailedException->getMessage(),
                        ],
                    );
                }
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    'An unexpected error occurred while queuing Facebook post synchronization.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

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
     * @throws ModelNotFoundException
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
                'connected_by_user_id',
                $userId,
            )
            ->where(
                'facebook_page_id',
                $facebookPageId,
            )
            ->first();

        if ($page === null) {
            Log::warning(
                'Facebook Page lookup returned no result for authenticated user.',
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
            | Do not expose another user's Page to the caller.
            |
            | We also avoid logging another user's internal identifiers
            | because those values are not necessary for normal diagnostics.
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

            throw (new ModelNotFoundException())
                ->setModel(
                    FacebookPage::class,
                    [$facebookPageId],
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

    /**
     * Get global application settings.
     *
     * Fails closed if the settings record is unavailable.
     *
     * @throws RuntimeException
     */
    private function getSystemSettings(
        ?string $requestId = null,
    ): SystemSetting {
        Log::debug('Loading system settings for Facebook request.', [
            'request_id' => $requestId,
        ]);

        $settings = SystemSetting::query()->first();

        if ($settings === null) {
            Log::critical(
                'System settings record was not found while processing Facebook request.',
                [
                    'request_id' => $requestId,
                ],
            );

            throw new RuntimeException(
                'System settings are not configured.',
            );
        }

        Log::debug('System settings loaded successfully.', [
            'request_id' => $requestId,
            'facebook_sync_enabled' => (bool) $settings->facebook_sync_enabled,
            'facebook_posts_enabled' => (bool) $settings->facebook_posts_enabled,
        ]);

        return $settings;
    }

    /**
     * Resolve or create a request correlation ID.
     */
    private function requestId(Request $request): string
    {
        $existingRequestId = $request->header('X-Request-ID');

        if (
            is_string($existingRequestId)
            && trim($existingRequestId) !== ''
            && strlen($existingRequestId) <= 100
        ) {
            return trim($existingRequestId);
        }

        return (string) Str::uuid();
    }
}