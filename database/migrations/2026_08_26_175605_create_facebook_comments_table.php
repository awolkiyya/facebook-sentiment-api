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
        Schema::create('facebook_comments', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Post
            |--------------------------------------------------------------------------
            |
            | Internal Laravel UUID of facebook_posts.id.
            |
            */
            $table->foreignUuid('facebook_post_id')
                ->constrained('facebook_posts')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Facebook Comment Identity
            |--------------------------------------------------------------------------
            |
            | External Meta/Facebook comment ID.
            |
            */
            $table->string('facebook_comment_id')
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Parent Comment
            |--------------------------------------------------------------------------
            |
            | External Meta/Facebook comment ID of the parent comment.
            | NULL means this is a top-level comment.
            |
            */
            $table->string('parent_comment_id')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Author
            |--------------------------------------------------------------------------
            */
            $table->string('author_name')
                ->nullable();

            $table->string('author_facebook_id')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            |
            | Nullable because some Meta comment payloads may not contain
            | usable message content.
            |
            */
            $table->text('message')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Facebook URL
            |--------------------------------------------------------------------------
            */
            $table->text('permalink_url')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Comment Date
            |--------------------------------------------------------------------------
            */
            $table->timestamp('commented_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Engagement
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('like_count')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | AI Language Analysis
            |--------------------------------------------------------------------------
            |
            | Supported languages:
            | am = Amharic
            | om = Afaan Oromo
            | en = English
            |
            */
            $table->string('language')
                ->nullable();

            $table->decimal('language_confidence', 5, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Topic Analysis
            |--------------------------------------------------------------------------
            |
            | Represents the subject discussed by the resident in the
            | comment, which is different from the Facebook post topic.
            |
            */
            $table->string('topic')
                ->nullable();

            $table->decimal('topic_confidence', 5, 4)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Sentiment Analysis
            |--------------------------------------------------------------------------
            |
            | Supported sentiments:
            | negative
            | neutral
            | positive
            |
            */
            $table->string('sentiment')
                ->nullable();

            $table->decimal('sentiment_confidence', 5, 4)
                ->nullable();

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
                'facebook_post_id',
                'commented_at',
            ]);

            $table->index('author_facebook_id');

            $table->index('parent_comment_id');

            /*
            |--------------------------------------------------------------------------
            | AI Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('language');

            $table->index('topic');

            $table->index('sentiment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_comments');
    }
};