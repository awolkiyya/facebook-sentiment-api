<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserMfaMethod;
use App\Models\UserMfaRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

class MfaService
{
    /*
    |--------------------------------------------------------------------------
    | MFA Configuration
    |--------------------------------------------------------------------------
    */

    private const TOTP_TYPE = 'TOTP';

    private const TOTP_ISSUER = 'Municipal Social Analytics';

    /*
     * Allow the authenticator code to be accepted for:
     *
     *     previous time window
     *     current time window
     *     next time window
     *
     * This compensates for small clock differences between
     * the server and authenticator application.
     */
    private const TOTP_WINDOW = 1;

    /*
     * Number of recovery codes generated for each MFA setup.
     */
    private const RECOVERY_CODE_COUNT = 10;


    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly Google2FA $google2fa,
    ) {
        /*
         * Configure the allowed TOTP time-window drift.
         */
        $this->google2fa->setWindow(
            self::TOTP_WINDOW
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MFA Status
    |--------------------------------------------------------------------------
    */

    /**
     * Get the current MFA status for a user.
     *
     * Account-level MFA state is read from the users table.
     *
     * Detailed method information is read from
     * user_mfa_methods.
     */
    public function getStatus(User $user): array
    {
        /*
         * Get the currently active TOTP method.
         */
        $method = $this->getTotpMethod($user);

        /*
         * Account-level MFA state.
         *
         * These fields are synchronized when MFA is
         * confirmed or disabled.
         */
        $enabled = (bool) $user->mfa_enabled;

        $confirmed = $user->mfa_confirmed_at !== null;

        /*
         * Count only unused recovery codes.
         */
        $recoveryCodesCount = $user
            ->mfaRecoveryCodes()
            ->whereNull('used_at')
            ->count();

        return [
            /*
             * Account-level MFA state.
             */
            'enabled' => $enabled,

            /*
             * Whether an active TOTP method exists.
             */
            'configured' => $method !== null,

            /*
             * Whether the account has completed MFA confirmation.
             */
            'confirmed' => $confirmed,

            /*
             * Method information.
             */
            'type' => $method?->type,

            'name' => $method?->name,

            /*
             * Account-level confirmation timestamp.
             */
            'confirmed_at' => $user
                ->mfa_confirmed_at
                ?->toISOString(),

            /*
             * Last successful use of the active TOTP method.
             */
            'last_used_at' => $method
                ?->last_used_at
                ?->toISOString(),

            /*
             * Number of unused recovery codes.
             */
            'recovery_codes_remaining' => $recoveryCodesCount,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | TOTP Setup
    |--------------------------------------------------------------------------
    */

    /**
     * Start TOTP setup.
     *
     * This creates a new unconfirmed TOTP method.
     *
     * If a previous incomplete setup exists, it is removed.
     *
     * Starting setup does NOT enable MFA on the user account.
     */
    public function startTotpSetup(User $user): array
    {
        return DB::transaction(
            function () use ($user): array {

                /*
                 * Get the current active TOTP method.
                 */
                $existingMethod = $this->getTotpMethod($user);

                /*
                 * Never silently replace an already enabled MFA method.
                 */
                if ($existingMethod?->isEnabled()) {
                    throw new RuntimeException(
                        'Two-factor authentication is already enabled.'
                    );
                }

                /*
                 * Remove previous incomplete TOTP setup.
                 */
                UserMfaMethod::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'type',
                        self::TOTP_TYPE
                    )
                    ->whereNull('confirmed_at')
                    ->delete();

                /*
                 * Generate a cryptographically secure TOTP secret.
                 */
                $secret = $this->google2fa
                    ->generateSecretKey();

                /*
                 * Store the secret.
                 *
                 * UserMfaMethod should cast:
                 *
                 *     secret => encrypted
                 *
                 * Therefore the database receives
                 * an encrypted secret.
                 */
                $method = UserMfaMethod::create([
                    'user_id' => $user->getKey(),

                    'type' => self::TOTP_TYPE,

                    'name' => 'Authenticator App',

                    'secret' => $secret,

                    'confirmed_at' => null,

                    'last_used_at' => null,

                    'is_active' => true,
                ]);

                /*
                 * Generate the standard otpauth:// URI.
                 */
                $qrCodeUrl = $this->google2fa->getQRCodeUrl(
                    self::TOTP_ISSUER,
                    $user->email,
                    $secret
                );

                /*
                 * Make sure the account-level state remains
                 * disabled while setup is incomplete.
                 *
                 * Normally these values should already be
                 * false/null, but explicitly maintaining the
                 * state makes the behavior deterministic.
                 */
                $user->forceFill([
                    'mfa_enabled' => false,
                    'mfa_confirmed_at' => null,
                ])->save();

                return [
                    'id' => $method->id,

                    'type' => $method->type,

                    'name' => $method->name,

                    /*
                     * Returned only during setup.
                     */
                    'secret' => $secret,

                    /*
                     * otpauth:// URI.
                     */
                    'otpauth_url' => $qrCodeUrl,

                    'issuer' => self::TOTP_ISSUER,

                    'account' => $user->email,

                    'confirmed' => false,
                ];
            }
        );
    }


    /**
     * Confirm TOTP setup.
     *
     * A valid authenticator code:
     *
     * 1. Confirms the TOTP method.
     * 2. Enables MFA on the user account.
     * 3. Stores the confirmation timestamp.
     * 4. Invalidates previous recovery codes.
     * 5. Generates a fresh recovery-code set.
     *
     * Plaintext recovery codes are returned only once.
     */
    public function confirmTotpSetup(
        User $user,
        string $code
    ): array {
        return DB::transaction(
            function () use (
                $user,
                $code
            ): array {

                /*
                 * Lock the MFA method while confirming it.
                 *
                 * This prevents concurrent confirmation requests
                 * from both generating recovery codes.
                 */
                $method = UserMfaMethod::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'type',
                        self::TOTP_TYPE
                    )
                    ->where('is_active', true)
                    ->whereNull('confirmed_at')
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Two-factor authentication setup has not been started.'
                    );
                }

                /*
                 * This should normally be impossible because the
                 * query requires confirmed_at to be NULL, but keep
                 * the protection in place.
                 */
                if ($method->isEnabled()) {
                    throw new RuntimeException(
                        'Two-factor authentication is already enabled.'
                    );
                }

                if (!$method->isTotp()) {
                    throw new RuntimeException(
                        'Invalid MFA method.'
                    );
                }

                /*
                 * Normalize the supplied code.
                 */
                $normalizedCode = $this->normalizeTotpCode(
                    $code
                );

                /*
                 * Require exactly six digits.
                 */
                if (
                    strlen($normalizedCode) !== 6
                ) {
                    throw new RuntimeException(
                        'The verification code must contain exactly 6 digits.'
                    );
                }

                /*
                 * Verify against the stored encrypted secret.
                 */
                if (
                    !$this->verifyTotpCode(
                        $method,
                        $normalizedCode
                    )
                ) {
                    throw new RuntimeException(
                        'The verification code is invalid or has expired.'
                    );
                }

                /*
                 * Confirm the MFA method.
                 */
                $method->markAsConfirmed();

                /*
                 * Refresh the model so confirmed_at contains
                 * the actual persisted value.
                 */
                $method->refresh();

                /*
                 * Synchronize account-level MFA state.
                 *
                 * This is the important part:
                 *
                 *     users.mfa_enabled = true
                 *     users.mfa_confirmed_at = confirmation timestamp
                 */
                $user->forceFill([
                    'mfa_enabled' => true,

                    'mfa_confirmed_at' => $method->confirmed_at,
                ])->save();

                /*
                 * Any previous recovery codes are invalidated.
                 */
                $this->deleteRecoveryCodes(
                    $user
                );

                /*
                 * Generate a completely new recovery-code set.
                 */
                $recoveryCodes = $this->createRecoveryCodes(
                    $user
                );

                return [
                    /*
                     * IMPORTANT:
                     *
                     * The API contract uses "enabled",
                     * not "mfa_enabled".
                     */
                    'enabled' => true,

                    'confirmed' => true,

                    'method' => [
                        'id' => $method->id,

                        'type' => $method->type,

                        'name' => $method->name,

                        'confirmed_at' => $method
                            ->confirmed_at
                            ?->toISOString(),
                    ],

                    /*
                     * Plaintext recovery codes are returned
                     * only during this operation.
                     *
                     * They are NOT stored in plaintext.
                     */
                    'recovery_codes' => $recoveryCodes,
                ];
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TOTP Verification
    |--------------------------------------------------------------------------
    */

    /**
     * Verify an existing TOTP code.
     *
     * Used for:
     *
     * - MFA login
     * - MFA-sensitive operations
     * - security settings
     */
    public function verifyTotp(
        User $user,
        string $code
    ): bool {
        /*
         * Only a confirmed/enabled method may be used.
         */
        $method = $this->getEnabledTotpMethod(
            $user
        );

        if (!$method) {
            return false;
        }

        /*
         * Normalize the supplied code.
         */
        $normalizedCode = $this->normalizeTotpCode(
            $code
        );

        /*
         * A valid TOTP must contain exactly six digits.
         */
        if (
            strlen($normalizedCode) !== 6
        ) {
            return false;
        }

        /*
         * Verify the TOTP code.
         */
        if (
            !$this->verifyTotpCode(
                $method,
                $normalizedCode
            )
        ) {
            return false;
        }

        /*
         * Record successful MFA usage.
         */
        $method->markAsUsed();

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | General MFA Verification
    |--------------------------------------------------------------------------
    */

    /**
     * Verify either:
     *
     * 1. TOTP
     * 2. Recovery code
     *
     * Returns:
     *
     *     totp
     *     recovery_code
     *     null
     */
    public function verify(
        User $user,
        string $code
    ): ?string {

        /*
         * First attempt TOTP.
         */
        if (
            $this->verifyTotp(
                $user,
                $code
            )
        ) {
            return 'totp';
        }

        /*
         * Then attempt recovery code.
         */
        if (
            $this->verifyRecoveryCode(
                $user,
                $code
            )
        ) {
            return 'recovery_code';
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | Recovery Code Verification
    |--------------------------------------------------------------------------
    */

    /**
     * Verify and consume one recovery code.
     *
     * Recovery codes are stored as hashes.
     *
     * This operation is performed inside a database transaction
     * and the matched recovery-code rows are locked before
     * verification.
     *
     * This prevents concurrent requests from successfully
     * using the same recovery code.
     */
    public function verifyRecoveryCode(
        User $user,
        string $code
    ): bool {
        /*
         * Normalize the supplied recovery code.
         */
        $normalizedCode = $this->normalizeRecoveryCode(
            $code
        );

        if ($normalizedCode === '') {
            return false;
        }

        /*
         * Recovery codes have exactly 12 characters
         * after removing separators:
         *
         *     ABCD-EFGH-IJKL
         *
         * becomes:
         *
         *     ABCDEFGHIJKL
         */
        if (
            strlen($normalizedCode) !== 12
        ) {
            return false;
        }

        return DB::transaction(
            function () use (
                $user,
                $normalizedCode
            ): bool {

                /*
                 * Only unused recovery codes are candidates.
                 *
                 * lockForUpdate() is important here.
                 */
                $recoveryCodes = UserMfaRecoveryCode::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->whereNull('used_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($recoveryCodes as $recoveryCode) {

                    /*
                     * Compare the supplied plaintext code
                     * against the stored hash.
                     */
                    if (
                        !Hash::check(
                            $normalizedCode,
                            $recoveryCode->code_hash
                        )
                    ) {
                        continue;
                    }

                    /*
                     * Re-check the state while the row is locked.
                     */
                    if ($recoveryCode->used_at !== null) {
                        return false;
                    }

                    /*
                     * Consume the recovery code.
                     */
                    $recoveryCode->update([
                        'used_at' => now(),
                    ]);

                    /*
                     * Record successful MFA usage.
                     */
                    $method = $this->getEnabledTotpMethod(
                        $user
                    );

                    if ($method) {
                        $method->markAsUsed();
                    }

                    return true;
                }

                return false;
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Recovery Code Status
    |--------------------------------------------------------------------------
    */

    /**
     * Return the number of unused recovery codes.
     */
    public function recoveryCodesRemaining(
        User $user
    ): int {
        return $user
            ->mfaRecoveryCodes()
            ->whereNull('used_at')
            ->count();
    }


    /**
     * Get recovery-code status.
     *
     * Never return plaintext recovery codes.
     */
    public function getRecoveryCodeStatus(
        User $user
    ): array {
        $codes = $user
            ->mfaRecoveryCodes()
            ->get();

        return [
            'total' => $codes->count(),

            'remaining' => $codes
                ->whereNull('used_at')
                ->count(),

            'used' => $codes
                ->whereNotNull('used_at')
                ->count(),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Recovery Code Regeneration
    |--------------------------------------------------------------------------
    */

    /**
     * Regenerate all recovery codes.
     *
     * Existing recovery codes immediately become invalid.
     *
     * Returns the new plaintext recovery codes.
     */
    public function regenerateRecoveryCodes(
        User $user
    ): array {
        return DB::transaction(
            function () use ($user): array {

                /*
                 * Lock the enabled TOTP method so concurrent
                 * regeneration requests cannot race.
                 */
                $method = UserMfaMethod::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'type',
                        self::TOTP_TYPE
                    )
                    ->where('is_active', true)
                    ->whereNotNull('confirmed_at')
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Two-factor authentication is not enabled.'
                    );
                }

                /*
                 * Also ensure the account-level state agrees
                 * with the MFA method.
                 */
                if (!$user->mfa_enabled) {
                    throw new RuntimeException(
                        'Two-factor authentication is not enabled.'
                    );
                }

                /*
                 * Invalidate all existing recovery codes.
                 */
                $this->deleteRecoveryCodes(
                    $user
                );

                /*
                 * Create a new set.
                 */
                return $this->createRecoveryCodes(
                    $user
                );
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Disable MFA
    |--------------------------------------------------------------------------
    */

    /**
     * Disable TOTP MFA.
     *
     * The caller must verify the user's password and
     * current TOTP code before calling this method.
     */
    public function disableTotp(
        User $user
    ): void {
        DB::transaction(
            function () use ($user): void {

                /*
                 * Lock the MFA method.
                 */
                $method = UserMfaMethod::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'type',
                        self::TOTP_TYPE
                    )
                    ->where('is_active', true)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->first();

                if (!$method) {
                    throw new RuntimeException(
                        'Two-factor authentication is not configured.'
                    );
                }

                /*
                 * Deactivate rather than deleting the method.
                 *
                 * This preserves the MFA audit history.
                 */
                $method->deactivate();

                /*
                 * Synchronize account-level MFA state.
                 *
                 * MFA is now disabled for the user.
                 */
                $user->forceFill([
                    'mfa_enabled' => false,

                    'mfa_confirmed_at' => null,
                ])->save();

                /*
                 * Recovery codes become invalid immediately.
                 */
                $this->deleteRecoveryCodes(
                    $user
                );
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Cancel Setup
    |--------------------------------------------------------------------------
    */

    /**
     * Completely remove an incomplete TOTP setup.
     *
     * This does NOT modify the account-level MFA state because
     * an incomplete setup was never enabled.
     */
    public function cancelTotpSetup(
        User $user
    ): void {
        DB::transaction(
            function () use ($user): void {

                UserMfaMethod::query()
                    ->where(
                        'user_id',
                        $user->getKey()
                    )
                    ->where(
                        'type',
                        self::TOTP_TYPE
                    )
                    ->whereNull('confirmed_at')
                    ->delete();
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MFA State
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether MFA verification is required because TOTP
     * is enabled on the account or a confirmed TOTP method exists.
     *
     * The account-level flag is retained as a fail-closed safeguard:
     * a stale enabled flag must not silently bypass MFA.
     */
    public function hasEnabledTotp(User $user): bool
    {
        if ((bool) $user->mfa_enabled) {
            return true;
        }

        return UserMfaMethod::query()
            ->where('user_id', $user->getKey())
            ->where('type', self::TOTP_TYPE)
            ->where('is_active', true)
            ->whereNotNull('confirmed_at')
            ->whereNotNull('secret')
            ->exists();
    }


    /**
     * Get the active TOTP method.
     */
    public function getTotpMethod(
        User $user
    ): ?UserMfaMethod {
        return UserMfaMethod::query()
            ->where(
                'user_id',
                $user->getKey()
            )
            ->where(
                'type',
                self::TOTP_TYPE
            )
            ->where(
                'is_active',
                true
            )
            ->latest('created_at')
            ->first();
    }


    /**
     * Get only a confirmed/enabled TOTP method.
     *
     * The method itself must be:
     *
     * - active
     * - confirmed
     *
     * and the account must have MFA enabled.
     */
    public function getEnabledTotpMethod(
        User $user
    ): ?UserMfaMethod {
        if (!$user->mfa_enabled) {
            return null;
        }

        return UserMfaMethod::query()
            ->where(
                'user_id',
                $user->getKey()
            )
            ->where(
                'type',
                self::TOTP_TYPE
            )
            ->where(
                'is_active',
                true
            )
            ->whereNotNull(
                'confirmed_at'
            )
            ->latest('created_at')
            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | TOTP Verification Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a TOTP code against the encrypted secret.
     */
    private function verifyTotpCode(
        UserMfaMethod $method,
        string $code
    ): bool {
        $secret = $method->secret;

        if (
            !is_string($secret)
            || $secret === ''
        ) {
            return false;
        }

        try {
            return $this->google2fa->verifyKey(
                $secret,
                $code
            );
        } catch (\Throwable) {

            /*
             * Never allow malformed input or package errors
             * to break the authentication request.
             */
            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Recovery Code Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a new set of recovery codes.
     *
     * Plaintext codes are returned once.
     *
     * Only hashes are stored in the database.
     */
    private function createRecoveryCodes(
        User $user
    ): array {
        $codes = [];

        for (
            $i = 0;
            $i < self::RECOVERY_CODE_COUNT;
            $i++
        ) {
            $plainCode = $this->generateRecoveryCode();

            UserMfaRecoveryCode::create([
                'user_id' => $user->getKey(),

                'code_hash' => Hash::make(
                    $plainCode
                ),

                'used_at' => null,
            ]);

            $codes[] = $plainCode;
        }

        return $codes;
    }


    /**
     * Generate one cryptographically secure recovery code.
     *
     * Example:
     *
     *     7K4P-92MX-QL8T
     */
    private function generateRecoveryCode(): string
    {
        /*
         * Excludes visually ambiguous characters:
         *
         * I
         * O
         * 0
         * 1
         */
        $characters =
            'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        $parts = [];

        for (
            $part = 0;
            $part < 3;
            $part++
        ) {
            $value = '';

            for (
                $i = 0;
                $i < 4;
                $i++
            ) {
                $value .= $characters[
                    random_int(
                        0,
                        strlen($characters) - 1
                    )
                ];
            }

            $parts[] = $value;
        }

        return implode(
            '-',
            $parts
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Recovery Code Deletion
    |--------------------------------------------------------------------------
    */

    /**
     * Delete all recovery codes for a user.
     */
    private function deleteRecoveryCodes(
        User $user
    ): void {
        UserMfaRecoveryCode::query()
            ->where(
                'user_id',
                $user->getKey()
            )
            ->delete();
    }


    /*
    |--------------------------------------------------------------------------
    | Input Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize a TOTP code.
     *
     * Removes whitespace and non-digit characters.
     */
    private function normalizeTotpCode(
        string $code
    ): string {
        return preg_replace(
            '/\D/',
            '',
            trim($code)
        ) ?? '';
    }


    /**
     * Normalize a recovery code.
     *
     * Accepts:
     *
     *     ABCD-EFGH-IJKL
     *
     * or:
     *
     *     ABCDEFGHIJKL
     *
     * Both become:
     *
     *     ABCDEFGHIJKL
     */
    private function normalizeRecoveryCode(
        string $code
    ): string {
        return strtoupper(
            preg_replace(
                '/[^A-Z0-9]/',
                '',
                trim($code)
            ) ?? ''
        );
    }
}
