
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            | Analysis Date Range
            |--------------------------------------------------------------------------
            |
            | NULL date_from and date_to represent an all-time insight.
            |
            | Examples:
            | 2026-09-01 to 2026-09-15
            | 2026-09-16 to 2026-09-30
            | 2026-09-01 to 2026-09-30
            |
            */

            $table->date('date_from')->nullable();

            $table->date('date_to')->nullable();

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
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                ['status', 'created_at'],
                'post_ai_insights_status_created_index'
            );

            $table->index('priority');

            $table->index('sentiment');

            $table->index('primary_topic');

            $table->index(
                ['facebook_post_id', 'language', 'date_from', 'date_to'],
                'post_ai_insights_post_language_dates_index'
            );

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

        /*
        |--------------------------------------------------------------------------
        | Unique Insight Constraints
        |--------------------------------------------------------------------------
        |
        | PostgreSQL partial indexes:
        |
        | 1. One all-time insight per post and language.
        | 2. One insight per exact date range, post, and language.
        |
        */

        DB::statement('
            CREATE UNIQUE INDEX post_ai_insights_all_time_unique
            ON post_ai_insights (facebook_post_id, language)
            WHERE date_from IS NULL AND date_to IS NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX post_ai_insights_date_range_unique
            ON post_ai_insights (
                facebook_post_id,
                language,
                date_from,
                date_to
            )
            WHERE date_from IS NOT NULL AND date_to IS NOT NULL
        ');

        /*
        |--------------------------------------------------------------------------
        | Date Range Validation
        |--------------------------------------------------------------------------
        |
        | Both dates must be NULL or both must be supplied.
        | When supplied, the start date cannot be after the end date.
        |
        */

        DB::statement('
            ALTER TABLE post_ai_insights
            ADD CONSTRAINT post_ai_insights_date_range_check
            CHECK (
                (date_from IS NULL AND date_to IS NULL)
                OR
                (
                    date_from IS NOT NULL
                    AND date_to IS NOT NULL
                    AND date_from <= date_to
                )
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('post_ai_insights');
    }
};
