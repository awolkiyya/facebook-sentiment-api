<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        |
        | Core authentication and account-management table.
        |
        | Domain-specific data such as Facebook pages, posts, comments,
        | sentiment predictions, AI insights, reports, and audit logs
        | must remain in their respective tables.
        |
        */

        Schema::create('users', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')
                ->primary();

            $table->string('name');

            $table->string('email')
                ->unique();


            /*
            |--------------------------------------------------------------------------
            | Authentication
            |--------------------------------------------------------------------------
            */

            $table->timestamp('email_verified_at')
                ->nullable();

            $table->string('password');

            $table->rememberToken();


            /*
            |--------------------------------------------------------------------------
            | Account Status
            |--------------------------------------------------------------------------
            |
            | Controls whether the user is allowed to authenticate.
            |
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Login Tracking
            |--------------------------------------------------------------------------
            */

            /**
             * Timestamp of the last successful authentication.
             */
            $table->timestamp('last_login_at')
                ->nullable()
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Failed Login Tracking
            |--------------------------------------------------------------------------
            */

            /**
             * Number of consecutive failed password attempts.
             *
             * Reset after a successful authentication or according
             * to the application's lockout policy.
             */
            $table->unsignedInteger('failed_login_attempts')
                ->default(0);


            /**
             * Timestamp of the most recent failed authentication attempt.
             */
            $table->timestamp('last_failed_login_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Account Lockout
            |--------------------------------------------------------------------------
            */

            /**
             * Account remains locked until this timestamp.
             *
             * NULL means the account is not currently locked.
             */
            $table->timestamp('locked_until')
                ->nullable()
                ->index();


            /**
             * Number of lockout events for this account.
             *
             * The application can use this value to implement
             * progressive lockout policies.
             *
             * Example:
             *
             * 1st lock = 10 minutes
             * 2nd lock = 20 minutes
             * 3rd lock = 30 minutes
             * ...
             * Maximum duration = 120 minutes
             */
            $table->unsignedInteger('lockout_count')
                ->default(0);


            /*
            |--------------------------------------------------------------------------
            | Multi-Factor Authentication
            |--------------------------------------------------------------------------
            |
            | These fields represent MFA account state.
            |
            | Actual MFA secrets/recovery codes should not be stored
            | directly in this table.
            |
            | If TOTP or multiple MFA methods are implemented, use
            | a dedicated user_mfa_methods table.
            |
            */

            $table->boolean('mfa_enabled')
                ->default(false);

            $table->timestamp('mfa_confirmed_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();


            /*
            |--------------------------------------------------------------------------
            | Soft Deletes
            |--------------------------------------------------------------------------
            |
            | Keeps historical relationships and records while allowing
            | an account to be considered deleted.
            |
            */

            $table->softDeletes();
        });


        /*
        |--------------------------------------------------------------------------
        | Password Reset Tokens
        |--------------------------------------------------------------------------
        |
        | Used by Laravel's password reset functionality.
        |
        */

        Schema::create('password_reset_tokens', function (Blueprint $table) {

            $table->string('email')
                ->primary();

            $table->string('token');

            $table->timestamp('created_at')
                ->nullable();
        });


        /*
        |--------------------------------------------------------------------------
        | Sessions
        |--------------------------------------------------------------------------
        |
        | Stores database-backed authentication sessions when the
        | Laravel session driver is configured to use "database".
        |
        */

        Schema::create('sessions', function (Blueprint $table) {

            $table->string('id')
                ->primary();

            $table->uuid('user_id')
                ->nullable()
                ->index();

            $table->string('ip_address', 45)
                ->nullable();

            $table->text('user_agent')
                ->nullable();

            $table->longText('payload');

            $table->integer('last_activity')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            |
            | If a user is deleted, preserve the session record but
            | remove its user association.
            |
            */

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Drop Sessions
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('sessions');


        /*
        |--------------------------------------------------------------------------
        | Drop Password Reset Tokens
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('password_reset_tokens');


        /*
        |--------------------------------------------------------------------------
        | Drop Users
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('users');
    }
};
