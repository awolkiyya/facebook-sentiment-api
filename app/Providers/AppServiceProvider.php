<?php

namespace App\Providers;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\SystemLog;
use App\Models\SystemSetting;
use App\Models\LanguageModelVersion;
use App\Models\PriorityModelVersion;
use App\Models\SentimentModelVersion;
use App\Models\TopicModelVersion;

use App\Policies\UserPolicy;
use App\Policies\RolePolicy;
use App\Policies\PermissionPolicy;
use App\Policies\SystemLogPolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\AiModelVersionPolicy;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Authorization Policies
        |--------------------------------------------------------------------------
        */

        Gate::policy(
            User::class,
            UserPolicy::class
        );

        Gate::policy(
            Role::class,
            RolePolicy::class
        );

        Gate::policy(
            Permission::class,
            PermissionPolicy::class
        );

        Gate::policy(
            SystemLog::class,
            SystemLogPolicy::class
        );

        Gate::policy(
            SystemSetting::class,
            SystemSettingPolicy::class
        );

        /*
        |--------------------------------------------------------------------------
        | AI Model Version Policy
        |--------------------------------------------------------------------------
        |
        | All four AI pipelines use the same policy:
        |
        |     - Language
        |     - Sentiment
        |     - Priority
        |     - Topic
        |
        |--------------------------------------------------------------------------
        */

        Gate::policy(
            LanguageModelVersion::class,
            AiModelVersionPolicy::class
        );

        Gate::policy(
            PriorityModelVersion::class,
            AiModelVersionPolicy::class
        );

        Gate::policy(
            SentimentModelVersion::class,
            AiModelVersionPolicy::class
        );

        Gate::policy(
            TopicModelVersion::class,
            AiModelVersionPolicy::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Login Rate Limiting
        |--------------------------------------------------------------------------
        |
        | Protects:
        |
        |     POST /api/v1/auth/web-login
        |
        | Two independent limits are applied:
        |
        |     1. IP-based
        |     2. Account/email-based
        |
        |--------------------------------------------------------------------------
        */

        RateLimiter::for(
            'login',
            function (Request $request) {
                $email = Str::lower(
                    trim(
                        (string) $request->input('email')
                    )
                );

                $ip = $request->ip() ?? 'unknown';

                return [
                    /*
                    |--------------------------------------------------------------------------
                    | Maximum 10 login requests per minute per IP.
                    |--------------------------------------------------------------------------
                    */

                    Limit::perMinute(10)
                        ->by(
                            "login-ip:{$ip}"
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | Maximum 5 login requests per minute per account.
                    |--------------------------------------------------------------------------
                    */

                    Limit::perMinute(5)
                        ->by(
                            "login-account:{$email}"
                        ),
                ];
            }
        );

        /*
        |--------------------------------------------------------------------------
        | MFA Verification Rate Limiting
        |--------------------------------------------------------------------------
        |
        | Protects:
        |
        |     POST /api/v1/auth/mfa/verify
        |
        | This endpoint accepts:
        |
        |     - 6-digit TOTP codes
        |     - recovery codes
        |
        | Because TOTP codes are only 6 digits, brute-force protection
        | is especially important.
        |
        |--------------------------------------------------------------------------
        */

        RateLimiter::for(
            'mfa',
            function (Request $request) {
                $challengeId = trim(
                    (string) $request->input(
                        'challenge_id',
                        'unknown'
                    )
                );

                $ip = $request->ip() ?? 'unknown';

                return [
                    /*
                    |--------------------------------------------------------------------------
                    | Challenge + IP protection
                    |--------------------------------------------------------------------------
                    |
                    | Maximum 5 MFA verification attempts per minute
                    | for the same challenge from the same IP.
                    |
                    */

                    Limit::perMinute(5)
                        ->by(
                            'mfa-challenge:' .
                            sha1(
                                $challengeId
                                . '|'
                                . $ip
                            )
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | Global IP protection
                    |--------------------------------------------------------------------------
                    |
                    | Prevents an attacker from creating many different
                    | challenges and sending large numbers of MFA requests
                    | from the same IP address.
                    |
                    */

                    Limit::perMinute(20)
                        ->by(
                            'mfa-ip:' . $ip
                        ),
                ];
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Facebook Route Model Binding
        |--------------------------------------------------------------------------
        */

        /*
         * {facebookPage}
         *
         * Resolve against:
         *
         *     facebook_pages.facebook_page_id
         */

        Route::bind(
            'facebookPage',
            function (string $value) {
                return FacebookPage::query()
                    ->where(
                        'facebook_page_id',
                        $value
                    )
                    ->firstOrFail();
            }
        );

        /*
         * {facebookPost}
         *
         * Resolve against:
         *
         *     facebook_posts.id
         */

        Route::bind(
            'facebookPost',
            function (string $value) {
                return FacebookPost::query()
                    ->whereKey($value)
                    ->firstOrFail();
            }
        );
    }
}

