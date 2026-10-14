<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class MfaLoginChallengeService
{
    /**
     * MFA challenge lifetime in seconds.
     *
     * Five minutes gives the user enough time to open
     * their authenticator application while keeping the
     * temporary authentication state short-lived.
     */
    private const CHALLENGE_TTL_SECONDS = 300;

    /**
     * Maximum number of failed MFA verification attempts
     * allowed for a single challenge.
     *
     * Once this number is reached, the challenge is
     * permanently invalidated.
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Prefix used for MFA login challenges in the cache.
     */
    private const CACHE_PREFIX = 'auth:mfa:login:';

    /**
     * Prefix used for MFA failed-attempt counters.
     */
    private const ATTEMPT_PREFIX = 'auth:mfa:attempts:';


    /*
    |--------------------------------------------------------------------------
    | Challenge Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a temporary MFA login challenge.
     *
     * IMPORTANT:
     *
     * This does NOT authenticate the user.
     *
     * It only creates a short-lived proof that:
     *
     *     1. The user's credentials were already validated.
     *     2. MFA verification is now required.
     *
     * The actual Laravel web session must not be created
     * until this challenge is successfully verified.
     */
    public function createChallenge(
        User $user,
        bool $remember = false
    ): array {
        /*
         * Generate a cryptographically secure UUID.
         */
        $challengeId = (string) Str::uuid();

        /*
         * Store only the minimum information required
         * to complete the MFA login.
         *
         * Do NOT store:
         *
         * - password
         * - TOTP secret
         * - TOTP code
         * - recovery code
         */
        $payload = [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'created_at' => now()->toISOString(),
        ];

        Cache::put(
            $this->cacheKey($challengeId),
            $payload,
            now()->addSeconds(
                self::CHALLENGE_TTL_SECONDS
            )
        );

        /*
         * Initialize the failed-attempt counter.
         *
         * The counter has the same lifetime as the challenge.
         */
        Cache::put(
            $this->attemptKey($challengeId),
            0,
            now()->addSeconds(
                self::CHALLENGE_TTL_SECONDS
            )
        );

        return [
            'challenge_id' => $challengeId,
            'expires_in' => self::CHALLENGE_TTL_SECONDS,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Challenge Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve a challenge without consuming it.
     *
     * This method is useful when the application needs to inspect
     * a challenge before deciding what to do with it.
     *
     * IMPORTANT:
     *
     * Do not use this method as the final MFA verification step.
     * Use consumeChallenge() after validation succeeds.
     */
    public function getChallenge(
        string $challengeId
    ): ?array {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            return null;
        }

        $payload = Cache::get(
            $this->cacheKey($challengeId)
        );

        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }


    /*
    |--------------------------------------------------------------------------
    | Challenge Consumption
    |--------------------------------------------------------------------------
    */

    /**
     * Consume a challenge.
     *
     * A challenge can only be successfully consumed once.
     *
     * IMPORTANT:
     *
     * Cache::pull() retrieves and removes the cached value
     * in a single cache operation.
     *
     * This is safer than:
     *
     *     Cache::get()
     *     Cache::forget()
     *
     * because two concurrent requests could otherwise both
     * retrieve the challenge before either request deletes it.
     */
    public function consumeChallenge(
        string $challengeId
    ): array {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            throw new RuntimeException(
                'Invalid MFA challenge.'
            );
        }

        $payload = Cache::pull(
            $this->cacheKey($challengeId)
        );

        if (!is_array($payload)) {
            throw new RuntimeException(
                'The MFA challenge is invalid or has expired.'
            );
        }

        /*
         * Remove the failed-attempt counter after successful
         * challenge consumption.
         */
        Cache::forget(
            $this->attemptKey($challengeId)
        );

        return $payload;
    }


    /*
    |--------------------------------------------------------------------------
    | Challenge Invalidation
    |--------------------------------------------------------------------------
    */

    /**
     * Invalidate a challenge without completing MFA.
     *
     * This also removes its failed-attempt counter.
     */
    public function invalidateChallenge(
        string $challengeId
    ): void {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            return;
        }

        Cache::forget(
            $this->cacheKey($challengeId)
        );

        Cache::forget(
            $this->attemptKey($challengeId)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Challenge Existence
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether a challenge currently exists.
     */
    public function exists(
        string $challengeId
    ): bool {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            return false;
        }

        return Cache::has(
            $this->cacheKey($challengeId)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | User Information
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user ID associated with a challenge.
     *
     * Returns null when the challenge doesn't exist.
     */
    public function getUserId(
        string $challengeId
    ): ?string {
        $challenge = $this->getChallenge(
            $challengeId
        );

        if (!$challenge) {
            return null;
        }

        $userId = $challenge['user_id'] ?? null;

        if (!is_string($userId) && !is_int($userId)) {
            return null;
        }

        return (string) $userId;
    }


    /**
     * Get the remember-me state associated with a challenge.
     */
    public function shouldRemember(
        string $challengeId
    ): bool {
        $challenge = $this->getChallenge(
            $challengeId
        );

        if (!$challenge) {
            return false;
        }

        return (bool) ($challenge['remember'] ?? false);
    }


    /*
    |--------------------------------------------------------------------------
    | Failed MFA Attempts
    |--------------------------------------------------------------------------
    */

    /**
     * Record a failed MFA verification attempt.
     *
     * Returns the current number of failed attempts.
     *
     * If the maximum number of attempts is reached,
     * the challenge is automatically invalidated.
     *
     * Example:
     *
     *     Attempt 1 → challenge remains active
     *     Attempt 2 → challenge remains active
     *     Attempt 3 → challenge remains active
     *     Attempt 4 → challenge remains active
     *     Attempt 5 → challenge invalidated
     */
    public function recordFailedAttempt(
        string $challengeId
    ): int {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            throw new RuntimeException(
                'Invalid MFA challenge.'
            );
        }

        /*
         * Make sure the challenge still exists before
         * recording an attempt.
         */
        if (!$this->exists($challengeId)) {
            throw new RuntimeException(
                'The MFA challenge is invalid or has expired.'
            );
        }

        $attemptKey = $this->attemptKey(
            $challengeId
        );

        /*
         * Atomically increment the counter.
         */
        $attempts = Cache::increment(
            $attemptKey
        );

        /*
         * Some cache drivers may return false when the key
         * does not exist. Initialize it safely.
         */
        if ($attempts === false) {
            Cache::put(
                $attemptKey,
                1,
                now()->addSeconds(
                    self::CHALLENGE_TTL_SECONDS
                )
            );

            $attempts = 1;
        }

        /*
         * Invalidate the challenge once the maximum number
         * of failed attempts has been reached.
         */
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->invalidateChallenge(
                $challengeId
            );
        }

        return (int) $attempts;
    }


    /**
     * Get the number of failed MFA attempts for a challenge.
     */
    public function getFailedAttempts(
        string $challengeId
    ): int {
        $challengeId = $this->normalizeChallengeId(
            $challengeId
        );

        if ($challengeId === null) {
            return 0;
        }

        return (int) Cache::get(
            $this->attemptKey($challengeId),
            0
        );
    }


    /**
     * Get the remaining MFA attempts for a challenge.
     */
    public function getRemainingAttempts(
        string $challengeId
    ): int {
        $attempts = $this->getFailedAttempts(
            $challengeId
        );

        return max(
            0,
            self::MAX_FAILED_ATTEMPTS - $attempts
        );
    }


    /**
     * Check whether the challenge has reached its
     * maximum failed-attempt limit.
     */
    public function hasExceededAttempts(
        string $challengeId
    ): bool {
        return $this->getFailedAttempts(
            $challengeId
        ) >= self::MAX_FAILED_ATTEMPTS;
    }


    /*
    |--------------------------------------------------------------------------
    | Cache Keys
    |--------------------------------------------------------------------------
    */

    /**
     * Build the MFA challenge cache key.
     */
    private function cacheKey(
        string $challengeId
    ): string {
        return self::CACHE_PREFIX . $challengeId;
    }


    /**
     * Build the failed-attempt counter cache key.
     */
    private function attemptKey(
        string $challengeId
    ): string {
        return self::ATTEMPT_PREFIX . $challengeId;
    }


    /*
    |--------------------------------------------------------------------------
    | Challenge Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate and normalize a challenge ID.
     *
     * Challenge IDs are UUIDs generated by Laravel.
     */
    private function normalizeChallengeId(
        string $challengeId
    ): ?string {
        $challengeId = trim(
            $challengeId
        );

        if ($challengeId === '') {
            return null;
        }

        /*
         * Laravel UUID validation.
         */
        if (!Str::isUuid($challengeId)) {
            return null;
        }

        return $challengeId;
    }
}