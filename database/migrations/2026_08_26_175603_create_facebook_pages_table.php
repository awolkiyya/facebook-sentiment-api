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
        Schema::create('facebook_pages', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            |
            | Internal application UUID.
            |
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Connected Laravel User
            |--------------------------------------------------------------------------
            |
            | The local Laravel user who authorized this Facebook Page.
            |
            */
            $table->uuid('connected_by_user_id');

            $table->foreign('connected_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Facebook Page Identity
            |--------------------------------------------------------------------------
            |
            | Facebook's own Page ID.
            |
            */
            $table->string('facebook_page_id');

            $table->string('name');

            /*
            |--------------------------------------------------------------------------
            | Page Classification
            |--------------------------------------------------------------------------
            |
            | Primary Facebook category.
            |
            */
            $table->string('category')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Facebook Category List
            |--------------------------------------------------------------------------
            |
            | Facebook may return multiple categories.
            |
            */
            $table->json('category_list')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audience
            |--------------------------------------------------------------------------
            |
            | Latest follower count synchronized from Meta.
            |
            | This is NOT historical data.
            | Historical metrics should eventually be stored separately.
            |
            */
            $table->unsignedBigInteger('followers_count')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Facebook Page Access Token
            |--------------------------------------------------------------------------
            |
            | Page access token returned by Meta.
            |
            | IMPORTANT:
            |
            | - Encrypted by Laravel's encrypted cast.
            | - Never expose through an API Resource.
            | - Never log.
            | - Never send to the frontend.
            |
            */
            $table->text('access_token_encrypted');

            /*
            |--------------------------------------------------------------------------
            | Page Access Token Expiration
            |--------------------------------------------------------------------------
            |
            | The expiration timestamp of the Facebook Page access token.
            |
            | Nullable because the current Meta connection flow may not provide
            | an explicit expiration timestamp.
            |
            */
            $table->timestamp('page_access_token_expires_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Monitoring
            |--------------------------------------------------------------------------
            |
            | Determines whether this Page participates in the Facebook
            | intelligence / monitoring pipeline.
            |
            | false = connected but not monitored
            | true  = currently monitored
            |
            | Multiple Pages can be active simultaneously.
            |
            */
            $table->boolean('is_active')
                ->default(false)
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Synchronization
            |--------------------------------------------------------------------------
            |
            | Last successful metadata synchronization with Meta.
            |
            */
            $table->timestamp('last_synced_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Constraints
            |--------------------------------------------------------------------------
            */

            /*
             * A Laravel user cannot connect the same Facebook Page twice.
             *
             * The same Facebook Page may still be connected by different
             * Laravel users if they legitimately have access to it.
             */
            $table->unique([
                'connected_by_user_id',
                'facebook_page_id',
            ]);

            /*
             * Efficiently retrieve a user's monitored Pages.
             */
            $table->index([
                'connected_by_user_id',
                'is_active',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_pages');
    }
};

