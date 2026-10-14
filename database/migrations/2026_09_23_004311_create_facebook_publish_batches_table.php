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
        Schema::create('facebook_publish_batches', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Page
            |--------------------------------------------------------------------------
            |
            | References the locally connected Facebook Page.
            |
            */
            $table->uuid('facebook_page_id');

            /*
            |--------------------------------------------------------------------------
            | Batch Information
            |--------------------------------------------------------------------------
            */
            $table->string('name');
            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Batch Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'DRAFT',
                'SCHEDULED',
                'PROCESSING',
                'COMPLETED',
                'PARTIALLY_COMPLETED',
                'FAILED',
                'CANCELLED',
            ])->default('DRAFT');

            /*
            |--------------------------------------------------------------------------
            | Statistics
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('total_posts')->default(0);
            $table->unsignedInteger('pending_posts')->default(0);
            $table->unsignedInteger('processing_posts')->default(0);
            $table->unsignedInteger('published_posts')->default(0);
            $table->unsignedInteger('failed_posts')->default(0);
            $table->unsignedInteger('cancelled_posts')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Scheduling
            |--------------------------------------------------------------------------
            */
            $table->timestamp('scheduled_from')->nullable();
            $table->timestamp('scheduled_until')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('error_message')->nullable();

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

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['facebook_page_id', 'status']);
            $table->index('scheduled_from');
            $table->index('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_publish_batches');
    }
};