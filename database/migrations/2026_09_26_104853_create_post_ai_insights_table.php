<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Post
            |--------------------------------------------------------------------------
            */

            $table->uuid('facebook_post_id');

            $table->string('language', 2)->default('en');

            /*
            |--------------------------------------------------------------------------
            | Generated Insight Content
            |--------------------------------------------------------------------------
            |
            | Nullable because the record can be created before AI generation
            | finishes. Preserve previous successful content during regeneration.
            |
            */

            $table->text('summary')->nullable();

            $table->text('key_concern')->nullable();

            $table->jsonb('key_findings')->nullable();

            $table->jsonb('recommendations')->nullable();

            $table->string('primary_topic')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Overall Classification
            |--------------------------------------------------------------------------
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

            $table->decimal('confidence', 5, 4)->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Model And Pipeline
            |--------------------------------------------------------------------------
            */

            $table->string('model_name', 100)->nullable();

            $table->string('model_version', 100)->nullable();

            $table->string('analysis_version', 50)->default('v1');

            /*
            |--------------------------------------------------------------------------
            | Generation Lifecycle
            |--------------------------------------------------------------------------
            |
            | PENDING, PROCESSING, COMPLETED, FAILED
            |
            */

            $table->string('status', 20)->default('PENDING');

            /*
            |--------------------------------------------------------------------------
            | Evidence And Failure Tracking
            |--------------------------------------------------------------------------
            */

            $table->char('evidence_hash', 64)->nullable();

            $table->string('evidence_version', 100)->nullable();

            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('generation_attempts')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Optional Operational Metadata
            |--------------------------------------------------------------------------
            */

            $table->jsonb('metadata')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Generation Timestamp
            |--------------------------------------------------------------------------
            |
            | Update only after successful generation.
            |
            */

            $table->timestampTz('generated_at')->nullable();

            $table->timestampsTz();

            /*
            |--------------------------------------------------------------------------
            | Constraints And Indexes
            |--------------------------------------------------------------------------
            |
            | One current insight per post and language.
            |
            */

            $table->unique(
                ['facebook_post_id', 'language'],
                'post_ai_insights_post_language_unique'
            );

            $table->index(
                ['status', 'created_at'],
                'post_ai_insights_status_created_index'
            );

            $table->index('priority');

            $table->index('sentiment');

            $table->index('primary_topic');

            /*
            |--------------------------------------------------------------------------
            | Facebook Post Relationship
            |--------------------------------------------------------------------------
            */

            $table->foreign('facebook_post_id')
                ->references('id')
                ->on('facebook_posts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_ai_insights');
    }
};
