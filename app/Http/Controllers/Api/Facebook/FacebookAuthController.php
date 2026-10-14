<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\SystemSetting;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FacebookAuthController extends Controller
{
    /**
     * OAuth state lifetime in seconds.
     */
    private const OAUTH_STATE_TTL = 600;

    public function __construct(
        protected FacebookOAuthService $facebookOAuthService
    ) {
    }

    /**
     * Start Facebook OAuth authorization.
     *
     * This endpoint MUST be protected by auth:sanctum.
     *
     * Facebook OAuth is allowed only when the global Facebook
     * integration is enabled.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();

        /*
         * ---------------------------------------------------------
         * Validate authenticated user
         * ---------------------------------------------------------
         */
        if (!$user) {
            Log::warning(
                'Facebook OAuth authorization attempted without authenticated user'
            );

            return $this->frontendRedirect(
                'error',
                'You must be authenticated before connecting Facebook.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Check global Facebook integration setting
         * ---------------------------------------------------------
         *
         * This is the master switch from system_settings.
         *
         * If disabled:
         *
         * - Do not start OAuth.
         * - Do not create OAuth state.
         * - Do not redirect to Meta.
         */
        if (!$this->isFacebookIntegrationEnabled()) {
            Log::warning(
                'Facebook OAuth authorization blocked because Facebook integration is disabled',
                [
                    'user_id' => (string) $user->id,
                ]
            );

            return $this->frontendRedirect(
                'error',
                'Facebook integration is currently disabled.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Generate cryptographically secure OAuth state
         * ---------------------------------------------------------
         */
        $state = Str::random(64);

        Cache::put(
            $this->stateCacheKey($state),
            [
                'user_id' => (string) $user->id,
                'created_at' => now()->toISOString(),
            ],
            now()->addSeconds(self::OAUTH_STATE_TTL)
        );

        try {
            /*
             * -----------------------------------------------------
             * Generate Facebook authorization URL
             * -----------------------------------------------------
             */
            $authorizationUrl = $this->facebookOAuthService
                ->getAuthorizationUrl($state);

            Log::info(
                'Starting Facebook OAuth authorization',
                [
                    'user_id' => (string) $user->id,
                    'redirect_uri' => config(
                        'services.facebook.redirect_uri'
                    ),
                ]
            );

            return redirect()->away($authorizationUrl);

        } catch (Throwable $exception) {
            /*
             * -----------------------------------------------------
             * OAuth URL generation failed
             * -----------------------------------------------------
             *
             * Remove the unused state because the OAuth flow
             * will never reach the callback.
             */
            Cache::forget(
                $this->stateCacheKey($state)
            );

            /*
             * IMPORTANT:
             *
             * Do NOT log the exception message.
             *
             * HTTP exceptions can potentially contain:
             *
             * - client_secret
             * - authorization codes
             * - access tokens
             */
            Log::error(
                'Failed to start Facebook OAuth authorization',
                [
                    'user_id' => (string) $user->id,
                    'exception' => get_class($exception),
                ]
            );

            return $this->frontendRedirect(
                'error',
                'Facebook connection could not be started.'
            );
        }
    }

    /**
     * Handle Facebook OAuth callback.
     *
     * This endpoint MUST remain publicly accessible to Meta.
     *
     * IMPORTANT:
     *
     * This method connects/updates Facebook Pages only.
     *
     * It does NOT automatically start post synchronization.
     *
     * Synchronization is controlled separately by:
     *
     * - system_settings.facebook_sync_enabled
     * - system_settings.facebook_auto_sync
     * - system_settings.facebook_posts_enabled
     * - system_settings.facebook_comments_enabled
     * - facebook_pages.is_active
     *
     * and should be handled by the scheduler/jobs.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            /*
             * ---------------------------------------------------------
             * Handle OAuth error
             * ---------------------------------------------------------
             */
            if ($this->hasOAuthError($request)) {
                $this->logOAuthError($request);

                return $this->frontendRedirect(
                    'error',
                    'Facebook authorization was denied.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Validate OAuth state
             * ---------------------------------------------------------
             */
            $receivedState = $request
                ->string('state')
                ->toString();

            if ($receivedState === '') {
                Log::warning(
                    'Facebook OAuth state was not provided'
                );

                return $this->frontendRedirect(
                    'error',
                    'OAuth state was not provided.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Consume OAuth state
             * ---------------------------------------------------------
             *
             * Cache::pull() makes the OAuth state single-use.
             */
            $storedState = Cache::pull(
                $this->stateCacheKey($receivedState)
            );

            if (!is_array($storedState)) {
                Log::warning(
                    'Facebook OAuth state validation failed'
                );

                return $this->frontendRedirect(
                    'error',
                    'Invalid or expired Facebook authorization session.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Resolve local user
             * ---------------------------------------------------------
             */
            $localUserId = $storedState['user_id'] ?? null;

            if (
                !is_string($localUserId)
                || trim($localUserId) === ''
            ) {
                Log::error(
                    'Facebook OAuth state did not contain a valid local user ID'
                );

                return $this->frontendRedirect(
                    'error',
                    'The Facebook connection could not be associated with your account.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Authorization code
             * ---------------------------------------------------------
             *
             * The code is intentionally never logged.
             */
            $code = $request
                ->string('code')
                ->toString();

            if ($code === '') {
                Log::warning(
                    'Facebook OAuth authorization code was not provided',
                    [
                        'user_id' => $localUserId,
                    ]
                );

                return $this->frontendRedirect(
                    'error',
                    'Facebook authorization code was not provided.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Exchange authorization code for User Access Token
             * ---------------------------------------------------------
             */
            $tokenResponse = $this->facebookOAuthService
                ->exchangeCodeForUserToken($code);

            $userAccessToken = $tokenResponse['access_token'] ?? null;

            if (
                !is_string($userAccessToken)
                || trim($userAccessToken) === ''
            ) {
                throw new RuntimeException(
                    'Facebook did not return a User Access Token.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Retrieve Facebook user
             * ---------------------------------------------------------
             */
            $facebookUser = $this->facebookOAuthService
                ->getUser($userAccessToken);

            $facebookUserId = $facebookUser['id'] ?? null;
            $facebookUserName = $facebookUser['name'] ?? null;

            if (
                !is_string($facebookUserId)
                || trim($facebookUserId) === ''
            ) {
                throw new RuntimeException(
                    'Facebook user ID was not returned.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Retrieve authorized Facebook Pages
             * ---------------------------------------------------------
             */
            $pagesResponse = $this->facebookOAuthService
                ->getUserPages($userAccessToken);

            $pages = $pagesResponse['data'] ?? [];

            if (!is_array($pages)) {
                throw new RuntimeException(
                    'Invalid Facebook Pages response.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Filter unusable Page records
             * ---------------------------------------------------------
             */
            $pages = collect($pages)
                ->filter(
                    static function ($page): bool {
                        return is_array($page)
                            && isset($page['id'])
                            && is_string($page['id'])
                            && trim($page['id']) !== ''
                            && isset($page['name'])
                            && is_string($page['name'])
                            && trim($page['name']) !== ''
                            && isset($page['access_token'])
                            && is_string($page['access_token'])
                            && trim($page['access_token']) !== '';
                    }
                )
                ->values()
                ->all();

            if ($pages === []) {
                Log::warning(
                    'No usable Facebook Pages were returned',
                    [
                        'user_id' => $localUserId,
                        'facebook_user_id' => $facebookUserId,
                    ]
                );

                return $this->frontendRedirect(
                    'error',
                    'No Facebook Pages were available to connect.'
                );
            }

            /*
             * ---------------------------------------------------------
             * Persist authorized Pages
             * ---------------------------------------------------------
             *
             * Multiple Pages are supported.
             *
             * The transaction is completed before this method
             * returns. No queue jobs are dispatched here.
             */
            $storedPages = DB::transaction(
                function () use (
                    $pages,
                    $localUserId
                ): array {
                    $storedPages = [];

                    foreach ($pages as $page) {
                        $storedPages[] = $this->persistFacebookPage(
                            localUserId: $localUserId,
                            page: $page,
                        );
                    }

                    return $storedPages;
                }
            );

            if ($storedPages === []) {
                throw new RuntimeException(
                    'Facebook Pages were returned but none could be stored.'
                );
            }

            /*
             * ---------------------------------------------------------
             * DO NOT START AUTOMATIC SYNCHRONIZATION HERE
             * ---------------------------------------------------------
             *
             * OAuth is responsible only for:
             *
             *     Authentication
             *     ↓
             *     Authorization
             *     ↓
             *     Page connection
             *
             * Synchronization belongs to the scheduler/jobs.
             *
             * New Pages are stored with:
             *
             *     is_active = false
             *
             * Therefore they will not be automatically monitored
             * until explicitly activated.
             */

            /*
             * ---------------------------------------------------------
             * Log successful persistence
             * ---------------------------------------------------------
             *
             * NEVER log:
             *
             * - User Access Token
             * - Page Access Token
             * - OAuth code
             * - OAuth state
             */
            Log::info(
                'Facebook authorized Pages stored successfully',
                [
                    'user_id' => $localUserId,
                    'facebook_user_id' => $facebookUserId,
                    'facebook_user_name' => $facebookUserName,
                    'page_count' => count($storedPages),
                    'page_ids' => collect($storedPages)
                        ->pluck('facebook_page_id')
                        ->values()
                        ->all(),
                ]
            );

            /*
             * ---------------------------------------------------------
             * Successful OAuth completion
             * ---------------------------------------------------------
             */
            return $this->frontendRedirect(
                'success',
                sprintf(
                    '%d Facebook Page%s connected successfully.',
                    count($storedPages),
                    count($storedPages) === 1 ? '' : 's'
                )
            );

        } catch (Throwable $exception) {
            /*
             * IMPORTANT:
             *
             * Never log $exception->getMessage() here.
             *
             * Laravel HTTP client exceptions can contain URLs with
             * sensitive query parameters such as:
             *
             * - client_secret
             * - authorization code
             * - access_token
             */
            Log::error(
                'Facebook OAuth callback failed',
                [
                    'exception' => get_class($exception),
                ]
            );

            return $this->frontendRedirect(
                'error',
                'Facebook connection could not be completed.'
            );
        }
    }

    /**
     * Persist or update a Facebook Page.
     *
     * IMPORTANT:
     *
     * - access_token_encrypted is encrypted automatically by the model cast.
     * - Existing monitoring status is preserved.
     * - New Pages start with monitoring disabled.
     * - Page metadata is synchronized during connection.
     *
     * This method does NOT start post/comment synchronization.
     */
    private function persistFacebookPage(
        string $localUserId,
        array $page
    ): FacebookPage {
        /*
         * ---------------------------------------------------------
         * Extract Meta Page data
         * ---------------------------------------------------------
         */
        $facebookPageId = $page['id'] ?? null;
        $facebookPageName = $page['name'] ?? null;
        $pageAccessToken = $page['access_token'] ?? null;

        /*
         * ---------------------------------------------------------
         * Validate Facebook Page ID
         * ---------------------------------------------------------
         */
        if (
            !is_string($facebookPageId)
            || trim($facebookPageId) === ''
        ) {
            throw new RuntimeException(
                'Facebook Page ID was not returned.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Validate Facebook Page name
         * ---------------------------------------------------------
         */
        if (
            !is_string($facebookPageName)
            || trim($facebookPageName) === ''
        ) {
            throw new RuntimeException(
                'Facebook Page name was not returned.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Validate Page Access Token
         * ---------------------------------------------------------
         */
        if (
            !is_string($pageAccessToken)
            || trim($pageAccessToken) === ''
        ) {
            throw new RuntimeException(
                'Facebook Page Access Token was not returned.'
            );
        }

        /*
         * ---------------------------------------------------------
         * Find existing Page
         * ---------------------------------------------------------
         *
         * A Page connection belongs to:
         *
         *     local user + Facebook Page ID
         *
         * This allows the same Facebook Page to be connected by
         * different local users while preventing duplicate
         * connections for the same user.
         */
        $facebookPage = FacebookPage::query()
            ->where(
                'connected_by_user_id',
                $localUserId
            )
            ->where(
                'facebook_page_id',
                $facebookPageId
            )
            ->first();

        $isNewPage = $facebookPage === null;

        /*
         * ---------------------------------------------------------
         * Retrieve current Page metadata
         * ---------------------------------------------------------
         *
         * followers_count is retrieved directly from Meta using
         * the Page Access Token.
         *
         * If metadata synchronization fails, the Page connection
         * itself is still allowed to continue.
         */
        $metadata = [];

        try {
            $metadata = $this->facebookOAuthService
                ->getPageMetadata(
                    pageId: $facebookPageId,
                    pageAccessToken: $pageAccessToken,
                );
        } catch (Throwable $exception) {
            /*
             * IMPORTANT:
             *
             * Do not log the exception message because an HTTP
             * exception may contain the Page Access Token.
             */
            Log::warning(
                'Facebook Page metadata synchronization failed during connection',
                [
                    'local_user_id' => $localUserId,
                    'facebook_page_id' => $facebookPageId,
                    'exception' => get_class($exception),
                ]
            );
        }

        /*
         * ---------------------------------------------------------
         * Prepare Page data
         * ---------------------------------------------------------
         */
        $facebookPageData = [
            'connected_by_user_id' => $localUserId,

            'facebook_page_id' => $facebookPageId,

            'name' => $facebookPageName,

            'category' => isset($page['category'])
                ? (string) $page['category']
                : null,

            'category_list' => isset($page['category_list'])
                && is_array($page['category_list'])
                ? $page['category_list']
                : [],

            'followers_count' => $metadata['followers_count'] ?? null,

            /*
             * Laravel's encrypted cast handles encryption.
             */
            'access_token_encrypted' => $pageAccessToken,

            /*
             * The current OAuth flow does not provide an explicit
             * Page Access Token expiration timestamp.
             */
            'page_access_token_expires_at' => null,

            /*
             * Page metadata was synchronized during connection.
             *
             * This is metadata synchronization only. It does NOT
             * mean that posts/comments have been synchronized.
             */
            'last_synced_at' => now(),
        ];

        /*
         * ---------------------------------------------------------
         * New Page
         * ---------------------------------------------------------
         */
        if ($isNewPage) {
            /*
             * New Pages are connected but NOT automatically
             * enabled for monitoring.
             */
            $facebookPageData['is_active'] = false;

            $facebookPage = new FacebookPage();

            $facebookPage->fill(
                $facebookPageData
            );

            $facebookPage->save();

            return $facebookPage;
        }

        /*
         * ---------------------------------------------------------
         * Existing Page
         * ---------------------------------------------------------
         *
         * IMPORTANT:
         *
         * We intentionally do not modify is_active.
         *
         * If the existing Page was being monitored before
         * reconnecting Facebook, it remains monitored.
         */
        $facebookPage->fill(
            $facebookPageData
        );

        $facebookPage->save();

        return $facebookPage;
    }

    /**
     * Determine whether the global Facebook integration is enabled.
     *
     * The setting is stored in the system_settings table.
     *
     * This controller treats the first system settings record as
     * the application's global settings record.
     */
    private function isFacebookIntegrationEnabled(): bool
    {
        $settings = SystemSetting::query()->first();

        /*
         * Fail closed.
         *
         * If the global settings record does not exist, Facebook
         * OAuth should not be allowed accidentally.
         */
        if ($settings === null) {
            Log::error(
                'Facebook integration check failed because system settings were not found'
            );

            return false;
        }

        return (bool) $settings->facebook_sync_enabled;
    }

    /**
     * Determine whether Meta returned an OAuth error.
     */
    private function hasOAuthError(
        Request $request
    ): bool {
        return $request->filled('error')
            || $request->filled('error_code')
            || $request->filled('error_message');
    }

    /**
     * Log OAuth error information safely.
     *
     * Never log:
     *
     * - authorization code
     * - access token
     * - state
     */
    private function logOAuthError(
        Request $request
    ): void {
        Log::warning(
            'Facebook OAuth authorization failed',
            [
                'error' => $request->input('error'),
                'error_code' => $request->input('error_code'),
                'error_reason' => $request->input('error_reason'),
                'error_description' => $request->input(
                    'error_description'
                ),
                'error_message' => $request->input(
                    'error_message'
                ),
            ]
        );
    }

    /**
     * Generate OAuth state cache key.
     */
    private function stateCacheKey(
        string $state
    ): string {
        return "facebook_oauth_state:{$state}";
    }

    /**
     * Redirect to frontend after OAuth result.
     */
    private function frontendRedirect(
        string $status,
        string $message
    ): RedirectResponse {
        $frontendUrl = config('app.frontend_url');

        if (
            !is_string($frontendUrl)
            || trim($frontendUrl) === ''
        ) {
            Log::error(
                'APP frontend URL is not configured for Facebook OAuth redirect'
            );

            return redirect()->away('/');
        }

        $frontendUrl = rtrim($frontendUrl, '/');

        $url = $frontendUrl
            . '/en/dashboard/facebook/pages?facebook='
            . rawurlencode($status)
            . '&message='
            . rawurlencode($message);

        return redirect()->away($url);
    }
}