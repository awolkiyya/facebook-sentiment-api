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
        Schema::create('facebook_posts', function (Blueprint $table) {

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
            */
            $table->foreignUuid('facebook_page_id')
                ->constrained('facebook_pages')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Facebook Post Identity
            |--------------------------------------------------------------------------
            */
            $table->string('facebook_post_id')
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            */
            $table->text('message')
                ->nullable();

            $table->text('permalink_url')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Topic Analysis
            |--------------------------------------------------------------------------
            |
            | Stores the topic identified from the Facebook Page post itself.
            | This is separate from comment topics, which belong to
            | facebook_comments.
            |
            */
            $table->string('topic')
                ->nullable();

            $table->decimal('topic_confidence', 5, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Publication
            |--------------------------------------------------------------------------
            */
            $table->timestamp('published_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Engagement
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('like_count')
                ->default(0);

            $table->unsignedBigInteger('comment_count')
                ->default(0);

            $table->unsignedBigInteger('share_count')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Original Meta Payload
            |--------------------------------------------------------------------------
            */
            $table->json('raw_payload')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index([
                'facebook_page_id',
                'published_at',
            ]);

            $table->index('topic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_posts');
    }
};