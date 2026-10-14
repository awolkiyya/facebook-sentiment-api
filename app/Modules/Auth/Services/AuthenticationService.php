<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\AccountLockedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuthenticationService
{
    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    */

    private const MAX_FAILED_LOGIN_ATTEMPTS = 5;

    private const LOCK_DURATION_MINUTES = 10;

    private const MAX_LOCK_DURATION_MINUTES = 120;


    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    private const RATE_LIMIT_DECAY_SECONDS = 60;


    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    /**
     * Validate user credentials.
     *
     * This method:
     *
     * - Applies login rate limiting.
     * - Finds the user.
     * - Checks account lock status.
     * - Verifies the password.
     * - Checks account status.
     * - Tracks failed login attempts.
     * - Locks accounts after repeated failures.
     * - Resets temporary failure state after success.
     *
     * IMPORTANT:
     *
     * This method does NOT:
     *
     * - create a Laravel session
     * - complete MFA
     * - record last_login_at
     *
     * Session creation is handled by AuthSessionService.
     *
     * last_login_at is updated only after the complete
     * authentication flow has successfully finished.
     *
     * @param array{
     *     email: string,
     *     password: string
     * } $data
     *
     * @throws ValidationException
     * @throws AccountLockedException
     * @throws TooManyRequestsHttpException
     */
    public function validateCredentials(array $data): User
    {
        /*
        |--------------------------------------------------------------------------
        | Normalize Credentials
        |--------------------------------------------------------------------------
        */

        $email = strtolower(
            trim($data['email'])
        );

        $password = $data['password'];


        /*
        |--------------------------------------------------------------------------
        | Rate Limit
        |--------------------------------------------------------------------------
        */

        $rateLimitKey = $this->getRateLimitKey(
            $email,
            request()->ip()
        );

        if (
            RateLimiter::tooManyAttempts(
                $rateLimitKey,
                self::RATE_LIMIT_MAX_ATTEMPTS
            )
        ) {
            $seconds = RateLimiter::availableIn(
                $rateLimitKey
            );

            throw new TooManyRequestsHttpException(
                $seconds,
                'Too many login attempts. Please try again later.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        |
        | Only relationships required by AuthUserResource are loaded.
        |
        */

        $user = User::query()
            ->where('email', $email)
            ->with([
                // 'avatar',
                'roles.permissions',
            ])
            ->first();


        /*
        |--------------------------------------------------------------------------
        | Account Lock
        |--------------------------------------------------------------------------
        */

        if ($user?->locked_until) {

            if ($user->locked_until->isFuture()) {
                $this->throwLockedException($user);
            }

            /*
            |--------------------------------------------------------------------------
            | Lock Expired
            |--------------------------------------------------------------------------
            */

            $user->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Verify Password
        |--------------------------------------------------------------------------
        |
        | Unknown users and invalid passwords intentionally follow
        | the same response path.
        |
        */

        $credentialsValid =
            $user
            && $user->password
            && Hash::check(
                $password,
                $user->password
            );

        if (! $credentialsValid) {

            RateLimiter::hit(
                $rateLimitKey,
                self::RATE_LIMIT_DECAY_SECONDS
            );

            if (! $user) {
                $this->throwInvalidCredentials();
            }

            $this->recordFailedLogin($user);

            $user = $user->fresh();

            if (
                $user->locked_until
                && $user->locked_until->isFuture()
            ) {
                $this->throwLockedException($user);
            }

            $this->throwInvalidCredentials();
        }


        /*
        |--------------------------------------------------------------------------
        | Account Status
        |--------------------------------------------------------------------------
        */

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => [
                    'Account is inactive.',
                ],
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Reset Failed Login State
        |--------------------------------------------------------------------------
        */

        if (
            $user->failed_login_attempts > 0
            || $user->last_failed_login_at !== null
            || $user->locked_until !== null
        ) {
            $user->update([
                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'locked_until' => null,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Clear Rate Limit
        |--------------------------------------------------------------------------
        |
        | Password authentication has succeeded.
        |
        | The MFA challenge, when enabled, is handled separately.
        |
        */

        RateLimiter::clear(
            $rateLimitKey
        );


        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        |--------------------------------------------------------------------------
        |
        | Do NOT update last_login_at here.
        |
        | If MFA is enabled, the user has only successfully
        | authenticated with their password at this point.
        |
        | The final login timestamp is updated after:
        |
        |     Password
        |          ↓
        |     MFA verification
        |          ↓
        |     Laravel session creation
        |
        */


        /*
        |--------------------------------------------------------------------------
        | Return User
        |--------------------------------------------------------------------------
        */

        return $user->fresh([
            // 'avatar',
            'roles.permissions',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | User Lookup
    |--------------------------------------------------------------------------
    */

    /**
     * Find an active user by ID for a pending authentication flow.
     *
     * IMPORTANT:
     *
     * This method does NOT authenticate the user.
     *
     * It only retrieves the user associated with a temporary
     * MFA login challenge.
     *
     * No Laravel session is created here.
     */
    public function findUserById(
        string $userId
    ): ?User {
        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->with([
                // 'avatar',
                'roles.permissions',
            ])
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    /**
     * Generate the login rate-limit key.
     */
    protected function getRateLimitKey(
        string $email,
        ?string $ip
    ): string {
        return 'login:' . sha1(
            strtolower($email)
            . '|'
            . ($ip ?? 'unknown')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Failed Login Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Record a failed login attempt and apply account lockout
     * when the maximum number of failures is reached.
     */
    protected function recordFailedLogin(
        User $user
    ): void {
        DB::transaction(function () use ($user) {

            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attempts =
                (int) $lockedUser->failed_login_attempts + 1;

            $now = now();


            /*
            |--------------------------------------------------------------------------
            | Failed Attempt
            |--------------------------------------------------------------------------
            */

            if (
                $attempts < self::MAX_FAILED_LOGIN_ATTEMPTS
            ) {
                $lockedUser->update([
                    'failed_login_attempts' => $attempts,
                    'last_failed_login_at' => $now,
                ]);

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Account Lockout
            |--------------------------------------------------------------------------
            */

            $lockoutCount =
                (int) $lockedUser->lockout_count + 1;

            $lockDuration = min(
                self::LOCK_DURATION_MINUTES * $lockoutCount,
                self::MAX_LOCK_DURATION_MINUTES
            );

            $lockedUser->update([
                'failed_login_attempts' => $attempts,
                'last_failed_login_at' => $now,
                'locked_until' => $now->copy()->addMinutes(
                    $lockDuration
                ),
                'lockout_count' => $lockoutCount,
            ]);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Authentication Errors
    |--------------------------------------------------------------------------
    */

    /**
     * Throw a generic invalid-credentials error.
     *
     * The same response is used for unknown emails and
     * incorrect passwords to prevent account enumeration.
     */
    protected function throwInvalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'email' => [
                'Invalid credentials.',
            ],
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    */

    /**
     * Throw the account locked exception.
     */
    protected function throwLockedException(
        User $user
    ): never {
        $remainingSeconds = max(
            0,
            (int) now()->diffInSeconds(
                $user->locked_until
            )
        );

        throw new AccountLockedException(
            message: $this->getLockMessage(
                $remainingSeconds
            ),
            remainingSeconds: $remainingSeconds,
            lockedUntil: $user->locked_until,
        );
    }


    /**
     * Generate the account lockout message.
     */
    protected function getLockMessage(
        int $remainingSeconds
    ): string {
        if ($remainingSeconds <= 0) {
            return 'Your account lock has expired. You can try logging in again.';
        }


        /*
        |--------------------------------------------------------------------------
        | Seconds
        |--------------------------------------------------------------------------
        */

        if ($remainingSeconds < 60) {
            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s.',
                $remainingSeconds,
                $remainingSeconds === 1
                    ? 'second'
                    : 'seconds'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Minutes
        |--------------------------------------------------------------------------
        */

        $minutes = intdiv(
            $remainingSeconds,
            60
        );

        $seconds = $remainingSeconds % 60;


        if ($minutes < 60) {

            if ($seconds > 0) {
                return sprintf(
                    'Your account is temporarily locked. Please try again in %d %s and %d %s.',
                    $minutes,
                    $minutes === 1
                        ? 'minute'
                        : 'minutes',
                    $seconds,
                    $seconds === 1
                        ? 'second'
                        : 'seconds'
                );
            }

            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s.',
                $minutes,
                $minutes === 1
                    ? 'minute'
                    : 'minutes'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Hours
        |--------------------------------------------------------------------------
        */

        $hours = intdiv(
            $minutes,
            60
        );

        $remainingMinutes = $minutes % 60;


        if ($remainingMinutes > 0) {
            return sprintf(
                'Your account is temporarily locked. Please try again in %d %s and %d %s.',
                $hours,
                $hours === 1
                    ? 'hour'
                    : 'hours',
                $remainingMinutes,
                $remainingMinutes === 1
                    ? 'minute'
                    : 'minutes'
            );
        }


        return sprintf(
            'Your account is temporarily locked. Please try again in %d %s.',
            $hours,
            $hours === 1
                ? 'hour'
                : 'hours'
        );
    }
}