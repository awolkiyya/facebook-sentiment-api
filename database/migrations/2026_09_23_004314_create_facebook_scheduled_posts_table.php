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
        Schema::create('facebook_scheduled_posts', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */
            $table->uuid('facebook_page_id');
            $table->uuid('publish_batch_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Post Type
            |--------------------------------------------------------------------------
            */
            $table->enum('post_type', [
                'TEXT',
                'PHOTO',
                'VIDEO',
                'LINK',
            ])->default('TEXT');

            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            */
            $table->longText('message')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Link / Media
            |--------------------------------------------------------------------------
            */
            $table->text('link_url')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_type')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Publishing Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'DRAFT',
                'SCHEDULED',
                'QUEUED',
                'PROCESSING',
                'PUBLISHED',
                'FAILED',
                'CANCELLED',
            ])->default('DRAFT');

            /*
            |--------------------------------------------------------------------------
            | Scheduling
            |--------------------------------------------------------------------------
            */
            $table->timestamp('scheduled_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Publishing Information
            |--------------------------------------------------------------------------
            */
            $table->string('facebook_post_id')->nullable();
            $table->timestamp('published_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Retry Handling
            |--------------------------------------------------------------------------
            */
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);

            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Error Information
            |--------------------------------------------------------------------------
            */
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreign('facebook_page_id')
                ->references('id')
                ->on('facebook_pages')
                ->cascadeOnDelete();

            $table->foreign('publish_batch_id')
                ->references('id')
                ->on('facebook_publish_batches')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index([
                'facebook_page_id',
                'status',
            ]);

            $table->index([
                'status',
                'scheduled_at',
            ]);

            $table->index('publish_batch_id');

            $table->index('facebook_post_id');

            $table->index('next_retry_at');

            $table->index('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_scheduled_posts');
    }
};