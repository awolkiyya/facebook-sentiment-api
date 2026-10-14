<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Facebook;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacebookPageResource;
use App\Jobs\Facebook\SyncFacebookPostsJob;
use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Services\Facebook\FacebookPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class FacebookPageController extends Controller
{
    public function __construct(
        private readonly FacebookPageService $facebookPageService
    ) {
    }

    /**
     * Get all Facebook Pages connected by the authenticated user.
     *
     * GET /api/v1/facebook/pages
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $pages = $this->facebookPageService->getUserPages(
            userId: (string) $user->id
        );

        return response()->json([
            'success' => true,
            'data' => [
                'pages' => FacebookPageResource::collection($pages),

                'summary' => [
                    'total' => $pages->count(),

                    'monitoring' => $pages
                        ->where('is_active', true)
                        ->count(),
                ],
            ],
        ]);
    }

    /**
     * Enable or disable Facebook Page monitoring.
     *
     * PATCH /api/v1/facebook/pages/{facebookPage}/monitoring
     *
     * When monitoring is enabled:
     *
     *     Page active
     *          ↓
     *     Initial post synchronization queued
     *
     * Subsequent automatic synchronization is handled by the
     * scheduler according to system settings.
     */
    public function updateMonitoring(
        Request $request,
        string $facebookPage
    ): JsonResponse {
        $user = $request->user();

        /*
         * ---------------------------------------------------------
         * Authentication
         * ---------------------------------------------------------
         */
        if (!$user) {
            Log::warning(
                'Facebook Page monitoring update failed: unauthenticated.',
                [
                    'facebook_page_parameter' => $facebookPage,
                    'ip' => $request->ip(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
         * ---------------------------------------------------------
         * Validate request
         * ---------------------------------------------------------
         */
        $validated = $request->validate([
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        $requestedIsActive = (bool) $validated['is_active'];

        Log::info(
            'Facebook Page monitoring update requested.',
            [
                'user_id' => (string) $user->id,
                'facebook_page_parameter' => $facebookPage,
                'requested_is_active' => $requestedIsActive,
            ]
        );

        /*
         * ---------------------------------------------------------
         * Global Facebook integration check
         * ---------------------------------------------------------
         *
         * Monitoring cannot be enabled when the global Facebook
         * integration has been disabled.
         */
        if (
            $requestedIsActive
            && !$this->isFacebookIntegrationEnabled()
        ) {
            Log::warning(
                'Facebook Page monitoring enable request blocked because Facebook integration is disabled.',
                [
                    'user_id' => (string) $user->id,
                    'facebook_page_parameter' => $facebookPage,
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Facebook integration is currently disabled.',
            ], 409);
        }

        /*
         * ---------------------------------------------------------
         * Resolve Page through authenticated user's ownership
         * ---------------------------------------------------------
         */
        try {
            $page = $this->facebookPageService->getUserPage(
                userId: (string) $user->id,
                facebookPageId: $facebookPage,
            );
        } catch (Throwable $exception) {
            /*
             * Do not expose exception messages, files, or line
             * numbers in production logs/API responses.
             */
            Log::error(
                'Failed to resolve Facebook Page for monitoring update.',
                [
                    'user_id' => (string) $user->id,
                    'facebook_page_parameter' => $facebookPage,
                    'exception' => get_class($exception),
                ]
            );

            throw $exception;
        }

        $wasActive = (bool) $page->is_active;

        Log::info(
            'Facebook Page resolved for monitoring update.',
            [
                'user_id' => (string) $user->id,
                'local_page_id' => (string) $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->name,
                'current_is_active' => $wasActive,
                'requested_is_active' => $requestedIsActive,
            ]
        );

        /*
         * ---------------------------------------------------------
         * Update monitoring
         * ---------------------------------------------------------
         *
         * Multiple Facebook Pages can be active simultaneously.
         */
        try {
            $page = $this->facebookPageService->updateMonitoring(
                facebookPage: $page,
                isActive: $requestedIsActive,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to update Facebook Page monitoring.',
                [
                    'user_id' => (string) $user->id,
                    'local_page_id' => (string) $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'requested_is_active' => $requestedIsActive,
                    'exception' => get_class($exception),
                ]
            );

            throw $exception;
        }

        /*
         * ---------------------------------------------------------
         * Detect monitoring state transition
         * ---------------------------------------------------------
         *
         * Initial synchronization should happen only when the
         * Page transitions:
         *
         *     OFF → ON
         *
         * This prevents repeated requests such as:
         *
         *     ON → ON
         *
         * from dispatching duplicate initial synchronization jobs.
         */
        $monitoringEnabledNow = !$wasActive && (bool) $page->is_active;

        /*
         * ---------------------------------------------------------
         * Queue initial post synchronization
         * ---------------------------------------------------------
         *
         * Only queue when:
         *
         *     1. Monitoring changed from OFF to ON
         *     2. Global Facebook integration is enabled
         *     3. Global Facebook post synchronization is enabled
         *
         * Automatic synchronization after this initial sync is
         * handled separately by the scheduler.
         */
        $initialPostSyncQueued = false;

        if (
            $monitoringEnabledNow
            && $this->isFacebookIntegrationEnabled()
            && $this->areFacebookPostsEnabled()
        ) {
            try {
                SyncFacebookPostsJob::dispatch(
                    (string) $page->id
                );

                $initialPostSyncQueued = true;

                Log::info(
                    'Initial Facebook Page post synchronization queued after monitoring was enabled.',
                    [
                        'user_id' => (string) $user->id,
                        'local_page_id' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                    ]
                );
            } catch (Throwable $exception) {
                /*
                 * The Page monitoring state has already been updated.
                 *
                 * Queue dispatch failure should not silently change
                 * the monitoring state back.
                 */
                Log::error(
                    'Failed to queue initial Facebook Page post synchronization.',
                    [
                        'user_id' => (string) $user->id,
                        'local_page_id' => (string) $page->id,
                        'facebook_page_id' => $page->facebook_page_id,
                        'exception' => get_class($exception),
                    ]
                );

                /*
                 * We do not throw here because the monitoring update
                 * itself succeeded.
                 *
                 * The scheduler/manual sync can retry synchronization.
                 */
            }
        }

        /*
         * ---------------------------------------------------------
         * Log final state
         * ---------------------------------------------------------
         */
        Log::info(
            'Facebook Page monitoring updated successfully.',
            [
                'user_id' => (string) $user->id,
                'local_page_id' => (string) $page->id,
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->name,
                'was_active' => $wasActive,
                'is_active' => (bool) $page->is_active,
                'initial_post_sync_queued' => $initialPostSyncQueued,
            ]
        );

        /*
         * ---------------------------------------------------------
         * Response
         * ---------------------------------------------------------
         */
        return response()->json([
            'success' => true,

            'message' => $page->is_active
                ? 'Facebook Page monitoring enabled successfully.'
                : 'Facebook Page monitoring disabled successfully.',

            'data' => [
                'page' => new FacebookPageResource($page),

                'monitoring' => [
                    'is_active' => (bool) $page->is_active,
                ],

                'synchronization' => [
                    'initial_post_sync_queued' => $initialPostSyncQueued,
                ],
            ],
        ]);
    }

    /**
     * Synchronize Facebook Page metadata manually.
     *
     * POST /api/v1/facebook/pages/{facebookPage}/sync
     *
     * This is a manual metadata synchronization action.
     *
     * It is intentionally separate from automatic post/comment
     * synchronization.
     */
    public function sync(
        Request $request,
        string $facebookPage
    ): JsonResponse {
        $user = $request->user();

        /*
         * ---------------------------------------------------------
         * Authentication
         * ---------------------------------------------------------
         */
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
         * ---------------------------------------------------------
         * Global Facebook integration check
         * ---------------------------------------------------------
         */
        if (!$this->isFacebookIntegrationEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook integration is currently disabled.',
            ], 409);
        }

        /*
         * ---------------------------------------------------------
         * Resolve Page through authenticated user's ownership
         * ---------------------------------------------------------
         */
        $page = $this->facebookPageService->getUserPage(
            userId: (string) $user->id,
            facebookPageId: $facebookPage,
        );

        /*
         * ---------------------------------------------------------
         * Synchronize Page metadata
         * ---------------------------------------------------------
         */
        try {
            $page = $this->facebookPageService->syncMetadata(
                facebookPage: $page
            );

            Log::info(
                'Facebook Page metadata synchronized successfully.',
                [
                    'user_id' => (string) $user->id,
                    'local_page_id' => (string) $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Facebook Page synchronized successfully.',
                'data' => new FacebookPageResource($page),
            ]);
        } catch (Throwable $exception) {
            Log::error(
                'Facebook Page metadata synchronization failed.',
                [
                    'user_id' => (string) $user->id,
                    'local_page_id' => (string) $page->id,
                    'facebook_page_id' => $page->facebook_page_id,
                    'exception' => get_class($exception),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Facebook Page synchronization failed.',
            ], 502);
        }
    }

    /**
     * Disconnect a Facebook Page.
     *
     * DELETE /api/v1/facebook/pages/{facebookPage}
     */
    public function destroy(
        Request $request,
        string $facebookPage
    ): JsonResponse {
        $user = $request->user();

        /*
         * ---------------------------------------------------------
         * Authentication
         * ---------------------------------------------------------
         */
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
         * ---------------------------------------------------------
         * Resolve Page through authenticated user's ownership
         * ---------------------------------------------------------
         */
        $page = $this->facebookPageService->getUserPage(
            userId: (string) $user->id,
            facebookPageId: $facebookPage,
        );

        $pageDatabaseId = (string) $page->id;
        $facebookPageId = (string) $page->facebook_page_id;

        /*
         * ---------------------------------------------------------
         * Disconnect Page
         * ---------------------------------------------------------
         */
        $this->facebookPageService->disconnect(
            facebookPage: $page
        );

        Log::info(
            'Facebook Page disconnected successfully.',
            [
                'user_id' => (string) $user->id,
                'local_page_id' => $pageDatabaseId,
                'facebook_page_id' => $facebookPageId,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Facebook Page disconnected successfully.',
            'data' => [
                'id' => $pageDatabaseId,
                'facebook_page_id' => $facebookPageId,
            ],
        ]);
    }

    /**
     * Determine whether the global Facebook integration is enabled.
     *
     * system_settings.facebook_sync_enabled is the master
     * Facebook integration switch.
     */
    private function isFacebookIntegrationEnabled(): bool
    {
        $settings = SystemSetting::query()->first();

        /*
         * Fail closed if system settings are unavailable.
         */
        if ($settings === null) {
            Log::error(
                'Facebook integration check failed because system settings were not found.'
            );

            return false;
        }

        return (bool) $settings->facebook_sync_enabled;
    }

    /**
     * Determine whether global Facebook post synchronization
     * is enabled.
     */
    private function areFacebookPostsEnabled(): bool
    {
        $settings = SystemSetting::query()->first();

        /*
         * Fail closed if system settings are unavailable.
         */
        if ($settings === null) {
            Log::error(
                'Facebook post synchronization check failed because system settings were not found.'
            );

            return false;
        }

        return (bool) $settings->facebook_posts_enabled;
    }
}