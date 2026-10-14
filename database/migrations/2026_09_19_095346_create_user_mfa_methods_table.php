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
        | User MFA Methods
        |--------------------------------------------------------------------------
        |
        | Stores individual MFA authentication methods belonging to a user.
        |
        | Examples:
        |
        | - TOTP authenticator application
        | - Passkey / WebAuthn
        | - Future MFA methods
        |
        | Sensitive secrets must be encrypted at the application level.
        |
        */

        Schema::create('user_mfa_methods', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')
                ->primary();


            /*
            |--------------------------------------------------------------------------
            | User Relationship
            |--------------------------------------------------------------------------
            */

            $table->uuid('user_id')
                ->index();


            /*
            |--------------------------------------------------------------------------
            | MFA Method
            |--------------------------------------------------------------------------
            |
            | Example values:
            |
            | TOTP
            | WEBAUTHN
            | EMAIL_OTP
            | SMS_OTP
            |
            */

            $table->string('type', 50);


            /*
            |--------------------------------------------------------------------------
            | Display Name
            |--------------------------------------------------------------------------
            |
            | Human-readable name for the registered MFA method.
            |
            | Example:
            |
            | "My Authenticator"
            | "Office Phone"
            | "MacBook Passkey"
            |
            */

            $table->string('name')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Encrypted Secret
            |--------------------------------------------------------------------------
            |
            | Used by methods such as TOTP.
            |
            | IMPORTANT:
            | The application must encrypt this value before storing it.
            |
            */

            $table->text('secret')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Confirmation
            |--------------------------------------------------------------------------
            |
            | NULL    = method has not been verified.
            | DATE    = method was successfully verified.
            |
            */

            $table->timestamp('confirmed_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Last Usage
            |--------------------------------------------------------------------------
            |
            | Useful for security monitoring and account activity.
            |
            */

            $table->timestamp('last_used_at')
                ->nullable();


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | Allows a method to be disabled/revoked without deleting
            | its historical record.
            |
            */

            $table->boolean('is_active')
                ->default(true)
                ->index();


            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();


            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            |
            | If the user is permanently deleted, their MFA methods
            | are permanently removed as well.
            |
            */

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();


            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            |
            | A user can have multiple MFA methods, but the same method
            | type can exist more than once.
            |
            | Example:
            |
            | User
            | ├── TOTP - Personal phone
            | └── TOTP - Backup phone
            |
            */

            $table->index([
                'user_id',
                'type',
                'is_active',
            ]);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_mfa_methods');
    }
};
