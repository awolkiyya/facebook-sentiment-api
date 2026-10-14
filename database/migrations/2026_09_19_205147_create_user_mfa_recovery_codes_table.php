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
        | User MFA Recovery Codes
        |--------------------------------------------------------------------------
        |
        | Stores one-time recovery codes that can be used when a user cannot
        | access their configured MFA method.
        |
        | IMPORTANT:
        | Recovery codes should be hashed before being stored.
        | The plaintext code should only be shown to the user when generated.
        |
        */

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {

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
            | Recovery Code Hash
            |--------------------------------------------------------------------------
            |
            | Stores the hashed recovery code.
            |
            | Example plaintext code shown to the user:
            |
            | 8K4P-7Q2M
            |
            | The database should contain only its hash.
            |
            */

            $table->string('code_hash');


            /*
            |--------------------------------------------------------------------------
            | Usage
            |--------------------------------------------------------------------------
            |
            | NULL = recovery code has not been used.
            | DATE = recovery code has already been consumed.
            |
            */

            $table->timestamp('used_at')
                ->nullable()
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
            | If the user is permanently deleted, all of their recovery
            | codes are permanently deleted as well.
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
            */

            $table->index([
                'user_id',
                'used_at',
            ]);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_mfa_recovery_codes');
    }
};