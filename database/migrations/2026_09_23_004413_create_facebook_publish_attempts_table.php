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
        Schema::create('facebook_publish_attempts', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Scheduled Post
            |--------------------------------------------------------------------------
            */
            $table->uuid('facebook_scheduled_post_id');

            /*
            |--------------------------------------------------------------------------
            | Attempt Number
            |--------------------------------------------------------------------------
            */
            $table->unsignedSmallInteger('attempt_number');

            /*
            |--------------------------------------------------------------------------
            | Attempt Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'STARTED',
                'SUCCESS',
                'FAILED',
            ])->default('STARTED');

            /*
            |--------------------------------------------------------------------------
            | Meta Request Information
            |--------------------------------------------------------------------------
            */
            $table->string('endpoint')->nullable();
            $table->string('http_method', 10)->default('POST');

            /*
            |--------------------------------------------------------------------------
            | Meta Response
            |--------------------------------------------------------------------------
            */
            $table->unsignedSmallInteger('http_status')->nullable();

            $table->string('facebook_post_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Error Information
            |--------------------------------------------------------------------------
            */
            $table->string('error_code')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Request / Response Metadata
            |--------------------------------------------------------------------------
            |
            | Do not store access tokens here.
            |
            */
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timing
            |--------------------------------------------------------------------------
            */
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Exception
            |--------------------------------------------------------------------------
            */
            $table->text('exception_message')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            */
            $table->foreign('facebook_scheduled_post_id')
                ->references('id')
                ->on('facebook_scheduled_posts')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index([
                'facebook_scheduled_post_id',
                'attempt_number',
            ]);

            $table->index([
                'facebook_scheduled_post_id',
                'status',
            ]);

            $table->index('facebook_post_id');
            $table->index('http_status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_publish_attempts');
    }
};