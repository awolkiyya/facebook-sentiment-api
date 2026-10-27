<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\MfaLoginVerifyRequest;
use App\Modules\Auth\Resources\AuthUserResource;
use App\Modules\Auth\Services\AuthSessionService;
use App\Modules\Auth\Services\AuthenticationService;
use App\Modules\Auth\Services\MfaLoginChallengeService;
use App\Services\ApiResponse;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authService,
        private readonly AuthSessionService $authSessionService,
        private readonly MfaService $mfaService,
        private readonly MfaLoginChallengeService $mfaLoginChallengeService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | WEB LOGIN
    |--------------------------------------------------------------------------
    |
    | Authentication:
    |
    |     Laravel Web Session
    |
    | Login flow:
    |
    |     Email + Password
    |          ↓
    |     Credential validation
    |          ↓
    |     MFA enabled?
    |       ├── No  → Authenticate session
    |       └── Yes → Create MFA challenge
    |                         ↓
    |                    Frontend MFA screen
    |                         ↓
    |                    MFA verification
    |                         ↓
    |                    Authenticate session
    |
    | IMPORTANT:
    |
    | When MFA is enabled, the Laravel web session is NOT created
    | until the MFA verification is successfully completed.
    |
    */

public function webLogin(
    LoginRequest $request
): JsonResponse {
    Log::info('Web login attempt', [
        'request_id' => $request->header('X-Request-ID'),
        'email' => $request->input('email'),
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ]);

    /*
    |--------------------------------------------------------------------------
    | Validate Request
    |--------------------------------------------------------------------------
    */

    $validated = $request->validated();

    /*
    |--------------------------------------------------------------------------
    | Validate Credentials
    |--------------------------------------------------------------------------
    */

    $user = $this->authService->validateCredentials($validated);

    /*
    |--------------------------------------------------------------------------
    | Load System Security Settings
    |--------------------------------------------------------------------------
    */

    $settings = SystemSetting::query()->first();

    $requireTwoFactor = (bool) (
        $settings?->require_two_factor ?? false
    );

    /*
    |--------------------------------------------------------------------------
    | Check User MFA State
    |--------------------------------------------------------------------------
    */

    $mfaEnabled = $this->mfaService->hasEnabledTotp($user);

    /*
    |--------------------------------------------------------------------------
    | Remember Me
    |--------------------------------------------------------------------------
    */

    $remember = (bool) (
        $validated['remember'] ?? false
    );

    /*
    |--------------------------------------------------------------------------
    | MFA Verification Required
    |--------------------------------------------------------------------------
    |
    | Users who have already enabled MFA must verify it before
    | completing login, regardless of the global setting.
    |
    */

    if ($mfaEnabled) {
        Log::info('MFA verification required for web login', [
            'request_id' => $request->header('X-Request-ID'),
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Pre-Authentication MFA Challenge
        |--------------------------------------------------------------------------
        */

        $challenge = $this->mfaLoginChallengeService
            ->createChallenge(
                $user,
                $remember
            );

        Log::info('MFA login challenge created', [
            'request_id' => $request->header('X-Request-ID'),
            'user_id' => $user->id,
            'challenge_id' => $challenge['challenge_id'],
            'expires_in' => $challenge['expires_in'],
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success(
            data: [
                'mfa_required' => true,
                'mfa_enabled' => true,
                'mfa_setup_required' => false,
                'challenge_required' => true,
                'challenge_id' => $challenge['challenge_id'],
                'expires_in' => $challenge['expires_in'],
            ],
            message: 'Two-factor authentication verification is required.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create Laravel Web Session
    |--------------------------------------------------------------------------
    |
    | Users without configured MFA can log in normally.
    | If MFA is required globally, the dashboard can prompt them
    | to configure it after login.
    |
    */

    $this->authSessionService->authenticate(
        $request,
        $user
    );

    /*
    |--------------------------------------------------------------------------
    | Update Last Login
    |--------------------------------------------------------------------------
    */

    $user->forceFill([
        'last_login_at' => now(),
    ])->save();

    /*
    |--------------------------------------------------------------------------
    | Load User Relations
    |--------------------------------------------------------------------------
    */

    $this->loadUserRelations($user);

    /*
    |--------------------------------------------------------------------------
    | Determine Primary Role
    |--------------------------------------------------------------------------
    */

    $primaryRole = null;

    if ($user->roles->isNotEmpty()) {
        $primaryRole = $user->roles
            ->first()
            ->name;
    }

    /*
    |--------------------------------------------------------------------------
    | Build Response
    |--------------------------------------------------------------------------
    */

    $resource = new AuthUserResource($user);

    $response = ApiResponse::success(
        data: [
            'authenticated' => true,
            'user' => $resource->resolve(),
            'security' => [
                'require_two_factor' => $requireTwoFactor,
                'mfa_setup_required' => (
                    $requireTwoFactor && !$mfaEnabled
                ),
                'mfa_verified' => false,
            ],
        ],
        message: 'Login successful.',
    );

    /*
    |--------------------------------------------------------------------------
    | Frontend Role Cookie
    |--------------------------------------------------------------------------
    |
    | Frontend routing hint only.
    | Never use this for backend authorization.
    |
    */

    if ($primaryRole !== null) {
        $response->withCookie(
            cookie(
                'role',
                $primaryRole,
                $remember ? 43200 : 120
            )
        );
    }

    return $response;
}

    /*
    |--------------------------------------------------------------------------
    | VERIFY WEB LOGIN MFA
    |--------------------------------------------------------------------------
    |
    | POST:
    |
    |     /api/v1/auth/mfa/verify
    |
    | Authentication:
    |
    |     No Laravel authenticated session yet.
    |
    | Request:
    |
    |     challenge_id
    |     code
    |
    | Supported codes:
    |
    |     TOTP
    |     Recovery code
    |
    | Successful flow:
    |
    |     Challenge
    |          ↓
    |     Verify MFA code
    |          ↓
    |     Consume challenge
    |          ↓
    |     Authenticate Laravel session
    |          ↓
    |     Update last_login_at
    |          ↓
    |     Return authenticated user
    |
    |--------------------------------------------------------------------------
    */

    public function verifyLoginMfa(
        MfaLoginVerifyRequest $request
    ): JsonResponse {
        $requestId = $request->header('X-Request-ID');

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $validated = $request->validated();

        $challengeId = $validated['challenge_id'];
        $code = trim((string) $validated['code']);

        Log::info('MFA login verification attempt', [
            'request_id' => $requestId,
            'challenge_id' => $challengeId,
            'code_length' => strlen($code),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Get Challenge
        |--------------------------------------------------------------------------
        |
        | Do NOT consume the challenge yet.
        |
        | An invalid MFA code should allow the user to retry while
        | the challenge is still valid and has remaining attempts.
        |
        */

        $challenge = $this->mfaLoginChallengeService
            ->getChallenge($challengeId);

        if ($challenge === null) {
            Log::warning(
                'MFA login verification failed: invalid or expired challenge',
                [
                    'request_id' => $requestId,
                    'challenge_id' => $challengeId,
                    'ip' => $request->ip(),
                ]
            );

            throw ValidationException::withMessages([
                'challenge_id' => [
                    'The MFA challenge is invalid or has expired.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Get User ID From Challenge
        |--------------------------------------------------------------------------
        |
        | Never trust a user_id supplied by the frontend.
        |
        | The challenge is the authoritative source of the pending
        | authentication identity.
        |
        */

        $userId = $challenge['user_id'] ?? null;

        if (!is_string($userId) && !is_int($userId)) {
            Log::warning('MFA challenge contains invalid user ID', [
                'request_id' => $requestId,
                'challenge_id' => $challengeId,
                'ip' => $request->ip(),
            ]);

            $this->mfaLoginChallengeService
                ->invalidateChallenge($challengeId);

            throw ValidationException::withMessages([
                'challenge_id' => [
                    'The MFA challenge is invalid or has expired.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Retrieve User
        |--------------------------------------------------------------------------
        */

        $user = $this->authService->findUserById(
            (string) $userId
        );

        if ($user === null) {
            Log::warning('MFA challenge references unavailable user', [
                'request_id' => $requestId,
                'challenge_id' => $challengeId,
                'ip' => $request->ip(),
            ]);

            $this->mfaLoginChallengeService
                ->invalidateChallenge($challengeId);

            throw ValidationException::withMessages([
                'challenge_id' => [
                    'The MFA challenge is invalid or has expired.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Confirm MFA Is Still Enabled
        |--------------------------------------------------------------------------
        |
        | The user could have disabled MFA after the challenge was
        | created.
        |
        */

        if (!$this->mfaService->hasEnabledTotp($user)) {
            Log::warning(
                'MFA verification attempted after MFA was disabled',
                [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'challenge_id' => $challengeId,
                    'ip' => $request->ip(),
                ]
            );

            $this->mfaLoginChallengeService
                ->invalidateChallenge($challengeId);

            throw ValidationException::withMessages([
                'challenge_id' => [
                    'MFA is no longer enabled for this account.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Lock Challenge Verification
        |--------------------------------------------------------------------------
        |
        | Prevent concurrent requests from successfully using the
        | same challenge.
        |
        */

        $lock = Cache::lock(
            'auth:mfa:login:lock:' . $challengeId,
            10
        );

        $lockAcquired = false;

        try {
            /*
            |--------------------------------------------------------------------------
            | Acquire Lock
            |--------------------------------------------------------------------------
            */

            $lock->block(3);

            $lockAcquired = true;

            /*
            |--------------------------------------------------------------------------
            | Re-check Challenge Inside Lock
            |--------------------------------------------------------------------------
            |
            | Another request could have consumed or invalidated the
            | challenge while this request was waiting for the lock.
            |
            */

            $challenge = $this->mfaLoginChallengeService
                ->getChallenge($challengeId);

            if ($challenge === null) {
                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge is invalid or has expired.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Challenge User Again
            |--------------------------------------------------------------------------
            */

            $challengeUserId = $challenge['user_id'] ?? null;

            if (
                !is_string($challengeUserId)
                && !is_int($challengeUserId)
            ) {
                $this->mfaLoginChallengeService
                    ->invalidateChallenge($challengeId);

                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge is invalid or has expired.',
                    ],
                ]);
            }

            if (
                (string) $challengeUserId
                !== (string) $user->getKey()
            ) {
                Log::critical(
                    'MFA challenge user mismatch during verification',
                    [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'challenge_id' => $challengeId,
                        'challenge_user_id' => $challengeUserId,
                    ]
                );

                $this->mfaLoginChallengeService
                    ->invalidateChallenge($challengeId);

                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Remaining Attempts
            |--------------------------------------------------------------------------
            */

            if (
                $this->mfaLoginChallengeService
                    ->hasExceededAttempts($challengeId)
            ) {
                $this->mfaLoginChallengeService
                    ->invalidateChallenge($challengeId);

                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge has expired. Please log in again.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify MFA Code
            |--------------------------------------------------------------------------
            |
            | MfaService::verify() supports:
            |
            |     TOTP
            |     Recovery code
            |
            | Returns:
            |
            |     'totp'
            |     'recovery_code'
            |     null
            |
            */

            $verificationMethod = $this->mfaService->verify(
                $user,
                $code
            );

            /*
            |--------------------------------------------------------------------------
            | Invalid MFA Code
            |--------------------------------------------------------------------------
            */

            if ($verificationMethod === null) {
                $failedAttempts = $this->mfaLoginChallengeService
                    ->recordFailedAttempt($challengeId);

                Log::warning('MFA login verification failed', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'challenge_id' => $challengeId,
                    'failed_attempts' => $failedAttempts,
                    'ip' => $request->ip(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Maximum Attempts Reached
                |--------------------------------------------------------------------------
                */

                if ($failedAttempts >= 5) {
                    throw ValidationException::withMessages([
                        'challenge_id' => [
                            'Too many incorrect MFA attempts. Please log in again.',
                        ],
                    ]);
                }

                throw ValidationException::withMessages([
                    'code' => [
                        'The authentication or recovery code is invalid or has expired.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Consume Challenge
            |--------------------------------------------------------------------------
            |
            | Only consume the challenge after MFA verification succeeds.
            |
            | consumeChallenge() must perform an atomic/single-use
            | removal of the challenge.
            |
            */

            try {
                $consumedChallenge = $this->mfaLoginChallengeService
                    ->consumeChallenge($challengeId);
            } catch (RuntimeException $e) {
                Log::warning('MFA challenge could not be consumed', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'challenge_id' => $challengeId,
                    'ip' => $request->ip(),
                    'exception' => $e->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge is invalid or has expired.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Consumed Challenge User
            |--------------------------------------------------------------------------
            */

            if (
                (string) ($consumedChallenge['user_id'] ?? '')
                !== (string) $user->getKey()
            ) {
                Log::critical('MFA challenge user mismatch', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'challenge_id' => $challengeId,
                ]);

                /*
                 * The challenge has already been consumed.
                 * Do not authenticate the session.
                 */

                throw ValidationException::withMessages([
                    'challenge_id' => [
                        'The MFA challenge is invalid.',
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Remember Me
            |--------------------------------------------------------------------------
            */

            $remember = (bool) (
                $consumedChallenge['remember'] ?? false
            );

            /*
            |--------------------------------------------------------------------------
            | Create Laravel Web Session
            |--------------------------------------------------------------------------
            |
            | This is the FIRST point at which the user becomes
            | authenticated.
            |
            */

            $this->authSessionService->authenticate(
                $request,
                $user
            );

            /*
            |--------------------------------------------------------------------------
            | Update Last Login
            |--------------------------------------------------------------------------
            */

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Load User Relations
            |--------------------------------------------------------------------------
            */

            $this->loadUserRelations($user);

            /*
            |--------------------------------------------------------------------------
            | Determine Primary Role
            |--------------------------------------------------------------------------
            */

            $primaryRole = null;

            if ($user->roles->isNotEmpty()) {
                $primaryRole = $user->roles
                    ->first()
                    ->name;
            }

            /*
            |--------------------------------------------------------------------------
            | Build Authenticated User Response
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | The frontend MFA verification flow expects:
            |
            |     data.authenticated
            | |   data.user
            |
            */

            $resource = new AuthUserResource($user);

            $response = ApiResponse::success(
                data: [
                    'authenticated' => true,
                    'user' => $resource->resolve(),
                    'security' => [
                        'mfa_verified' => true,
                        'mfa_method' => $verificationMethod,
                    ],
                ],
                message: 'Login successful.',
            );

            /*
            |--------------------------------------------------------------------------
            | Frontend Role Cookie
            |--------------------------------------------------------------------------
            |
            | Frontend routing hint only.
            | Never use this for backend authorization.
            |
            */

            if ($primaryRole !== null) {
                $response->withCookie(
                    cookie(
                        'role',
                        $primaryRole,
                        $remember ? 43200 : 120
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Success Log
            |--------------------------------------------------------------------------
            */

            Log::info('MFA login verification successful', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'challenge_id' => $challengeId,
                'method' => $verificationMethod,
                'remember' => $remember,
                'ip' => $request->ip(),
            ]);

            return $response;
        } finally {
            /*
            |--------------------------------------------------------------------------
            | Release Lock
            |--------------------------------------------------------------------------
            */

            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | WEB LOGOUT
    |--------------------------------------------------------------------------
    |
    | Authentication:
    |
    |     Laravel Web Session
    |
    */

    public function webLogout(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        Log::info('Web logout attempt', [
            'request_id' => $request->header('X-Request-ID'),
            'user_id' => $user?->id,
            'email' => $user?->email,
            'ip' => $request->ip(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Destroy Laravel Session
        |--------------------------------------------------------------------------
        */

        $this->authSessionService->logout(
            $request
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $response = ApiResponse::success(
            data: null,
            message: 'Successfully logged out.',
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Frontend Role Cookie
        |--------------------------------------------------------------------------
        */

        $response->withCookie(
            cookie()->forget('role')
        );

        Log::info('Web logout successful', [
            'request_id' => $request->header('X-Request-ID'),
            'user_id' => $user?->id,
        ]);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT USER RELATIONS
    |--------------------------------------------------------------------------
    */

    private function loadUserRelations(
        $user
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Common Relations
        |--------------------------------------------------------------------------
        */

        $relations = [
            // 'administrativeUnit.parent.parent',
            // 'avatar',
        ];

        /*
        |--------------------------------------------------------------------------
        | RBAC
        |--------------------------------------------------------------------------
        |
        | SYSTEM_ADMIN / MAYOR
        |
        */

        $relations[] = 'roles.permissions';

        $user->load($relations);
    }
}