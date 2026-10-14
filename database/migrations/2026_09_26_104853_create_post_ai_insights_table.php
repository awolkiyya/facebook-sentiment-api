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
        Schema::create('post_ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Post
            |--------------------------------------------------------------------------
            |
            | Each Facebook post has one current AI-generated insight.
            |
            | When new comments are analyzed or the AI model changes,
            | the existing insight is updated instead of creating
            | another insight record.
            |
            */
            $table->uuid('facebook_post_id')->unique();

            /*
            |--------------------------------------------------------------------------
            | Insight Content
            |--------------------------------------------------------------------------
            */
            $table->text('summary');

            $table->text('key_concern')->nullable();

            $table->string('primary_topic')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Overall Classification
            |--------------------------------------------------------------------------
            |
            | These values represent the aggregated interpretation
            | of the comments associated with the post.
            |
            */
            $table->string('sentiment')->nullable();

            $table->string('priority')->nullable();

            $table->boolean('actionable')->default(false);

            /*
            |--------------------------------------------------------------------------
            | Analysis Statistics
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('analyzed_comments')->default(0);

            /*
            | Confidence returned by the insight generation process.
            |
            | Example:
            | 0.9100 = 91%
            |
            */
            $table->decimal('confidence', 5, 4)->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Model Information
            |--------------------------------------------------------------------------
            |
            | Stores which generative model produced the insight.
            |
            | Example:
            | model_name    = qwen2.5:14b
            | model_version = 1
            |
            */
            $table->string('model_name')->nullable();

            $table->string('model_version')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Analysis Pipeline Version
            |--------------------------------------------------------------------------
            |
            | Version of your own insight-generation pipeline.
            |
            | Example:
            | v1
            | v2
            |
            */
            $table->string('analysis_version')->default('v1');

            /*
            |--------------------------------------------------------------------------
            | Generation Metadata
            |--------------------------------------------------------------------------
            */
            $table->timestamp('generated_at')->nullable();

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
            |
            | facebook_post_id already has a unique index because of
            | ->unique(), so no separate index is necessary.
            |
            */
            $table->index('priority');

            $table->index('sentiment');

            $table->index('primary_topic');

            $table->index('generated_at');

            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            */
            $table->foreign('facebook_post_id')
                ->references('id')
                ->on('facebook_posts')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_ai_insights');
    }
};