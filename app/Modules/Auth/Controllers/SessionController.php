<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\SessionService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessionService,
        private AuthSessionService $authSessionService
    ) {
        Log::debug('SessionController initialized');
    }


    /*
    |--------------------------------------------------------------------------
    | WEB
    |--------------------------------------------------------------------------
    */


    /**
     * Get authenticated web user.
     *
     * Authentication:
     *
     *     Laravel Sanctum
     *
     * Web authentication:
     *
     *     Stateful Laravel session
     *
     * Cookie:
     *
     *     laravel_session
     *
     * Route:
     *
     *     GET /api/v1/auth/web-me
     */
    public function webMe(
        Request $request
    ): JsonResponse {

        $start = microtime(true);

        Log::info('AUTH WEB-ME: request started', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),

            /*
             * Never log the actual cookie value.
             */
            'session_cookie_name' => config('session.cookie'),
            'session_cookie_present' => $request->hasCookie(
                config('session.cookie')
            ),

            /*
             * Session information.
             */
            'session_driver' => config('session.driver'),
            'session_id' => $this->safeSessionId($request),

            /*
             * Authentication state before service.
             */
            'auth_sanctum_check' => auth('sanctum')->check(),
            'auth_sanctum_id' => auth('sanctum')->id(),

            'default_auth_check' => auth()->check(),
            'default_auth_id' => auth()->id(),

            /*
             * Current request user.
             */
            'request_user_id' => $request->user()?->id,
        ]);


        try {

            /*
             |--------------------------------------------------------------------------
             | Get authenticated web context
             |--------------------------------------------------------------------------
             |
             | Expected structure:
             |
             | [
             |     'user' => [...],
             |     'security' => [
             |         'require_two_factor' => bool,
             |         'mfa_setup_required' => bool,
             |     ],
             | ]
             |
             */
            $data = $this->sessionService->webUser($request);

            $user = $data['user'] ?? null;

            Log::info('AUTH WEB-ME: service completed', [
                'authenticated' => $user !== null,

                'user_id' => $user['id'] ?? null,

                'mfa_enabled' => $user['mfa_enabled'] ?? false,

                'require_two_factor' =>
                    $data['security']['require_two_factor'] ?? false,

                'mfa_setup_required' =>
                    $data['security']['mfa_setup_required'] ?? false,

                'session_id' => $this->safeSessionId($request),

                'auth_sanctum_check' => auth('sanctum')->check(),
                'auth_sanctum_id' => auth('sanctum')->id(),

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);


            /*
             |--------------------------------------------------------------------------
             | Return authenticated context
             |--------------------------------------------------------------------------
             |
             | Returns:
             |
             | data:
             |   user:
             |     ...
             |
             |   security:
             |     require_two_factor
             |     mfa_setup_required
             |
             */
            $response = ApiResponse::success(
                $data,
                'User profile retrieved'
            );


            Log::info('AUTH WEB-ME: response successful', [
                'status' => $response->getStatusCode(),

                'user_id' => $user['id'] ?? null,

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);


            return $response;

        } catch (Throwable $e) {

            Log::error('AUTH WEB-ME: exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),

                'session_id' => $this->safeSessionId($request),

                'session_cookie_present' => $request->hasCookie(
                    config('session.cookie')
                ),

                'auth_sanctum_check' => auth('sanctum')->check(),
                'auth_sanctum_id' => auth('sanctum')->id(),

                'request_user_id' => $request->user()?->id,

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);

            throw $e;
        }
    }


    /**
     * Get current web session information.
     *
     * Route:
     *
     *     GET /api/v1/auth/web-session
     */
    public function webSession(
        Request $request
    ): JsonResponse {

        $start = microtime(true);

        Log::info('AUTH WEB-SESSION: request started', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),

            'session_driver' => config('session.driver'),
            'session_cookie_name' => config('session.cookie'),
            'session_cookie_present' => $request->hasCookie(
                config('session.cookie')
            ),

            'session_id' => $this->safeSessionId($request),

            'auth_sanctum_check' => auth('sanctum')->check(),
            'auth_sanctum_id' => auth('sanctum')->id(),

            'request_user_id' => $request->user()?->id,
        ]);


        try {

            $data = $this->sessionService->sessionInfo($request);

            $user = $data['user'] ?? null;

            Log::info('AUTH WEB-SESSION: service completed', [
                'authenticated' => $data['authenticated'] ?? false,

                'user_id' => $user['id'] ?? null,

                'mfa_enabled' => $user['mfa_enabled'] ?? false,

                'require_two_factor' =>
                    $data['security']['require_two_factor'] ?? false,

                'mfa_setup_required' =>
                    $data['security']['mfa_setup_required'] ?? false,

                'session_id' => $this->safeSessionId($request),

                'auth_sanctum_check' => auth('sanctum')->check(),
                'auth_sanctum_id' => auth('sanctum')->id(),

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);


            return ApiResponse::success(
                $data,
                'Session information retrieved'
            );

        } catch (Throwable $e) {

            Log::error('AUTH WEB-SESSION: exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),

                'session_id' => $this->safeSessionId($request),

                'auth_sanctum_check' => auth('sanctum')->check(),
                'auth_sanctum_id' => auth('sanctum')->id(),

                'request_user_id' => $request->user()?->id,

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);

            throw $e;
        }
    }


    /**
     * Logout current web session.
     *
     * Route:
     *
     *     POST /api/v1/auth/web-logout
     */
    public function webLogout(
        Request $request
    ): JsonResponse {

        $start = microtime(true);

        Log::info('AUTH WEB-LOGOUT: request started', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),

            'session_id' => $this->safeSessionId($request),

            'session_cookie_present' => $request->hasCookie(
                config('session.cookie')
            ),

            'auth_sanctum_check' => auth('sanctum')->check(),
            'auth_sanctum_id' => auth('sanctum')->id(),

            'request_user_id' => $request->user()?->id,
        ]);


        try {

            $this->authSessionService->logout($request);

            Log::info('AUTH WEB-LOGOUT: logout completed', [
                'session_id' => $this->safeSessionId($request),

                'auth_sanctum_check_after_logout' =>
                    auth('sanctum')->check(),

                'auth_sanctum_id_after_logout' =>
                    auth('sanctum')->id(),

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);


            return ApiResponse::success(
                null,
                'Successfully logged out.'
            );

        } catch (Throwable $e) {

            Log::error('AUTH WEB-LOGOUT: exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),

                'session_id' => $this->safeSessionId($request),

                'auth_sanctum_check' => auth('sanctum')->check(),
                'auth_sanctum_id' => auth('sanctum')->id(),

                'duration_ms' => round(
                    (microtime(true) - $start) * 1000,
                    2
                ),
            ]);

            throw $e;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */


    /**
     * Return a non-sensitive representation of the session ID.
     *
     * We intentionally DO NOT log the complete session ID.
     */
    private function safeSessionId(
        Request $request
    ): ?string {

        try {

            if (!$request->hasSession()) {
                return null;
            }

            $sessionId = $request->session()->getId();

            if (!$sessionId) {
                return null;
            }

            return substr($sessionId, 0, 8) . '...';

        } catch (Throwable) {

            return null;
        }
    }
}
