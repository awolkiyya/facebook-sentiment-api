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
        Schema::create('facebook_sync_runs', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            |
            | Application-level synchronization ID.
            |
            | Do NOT use the Laravel queue job ID as the primary identifier
            | for a synchronization run.
            |
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Page
            |--------------------------------------------------------------------------
            |
            | References the local facebook_pages.id UUID.
            |
            | This is NOT the external Meta/Facebook Page ID.
            |
            */
            $table->foreignUuid('facebook_page_id')
                ->constrained('facebook_pages')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Triggering User
            |--------------------------------------------------------------------------
            |
            | NULL when triggered automatically by scheduler/system.
            |
            | Set when a user manually starts synchronization.
            |
            */
            $table->foreignUuid('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Synchronization Trigger
            |--------------------------------------------------------------------------
            |
            | manual    → user explicitly requested synchronization
            | scheduler → scheduled/automated synchronization
            | system    → internal application/system synchronization
            |
            | IMPORTANT:
            | "retry" is intentionally NOT a trigger.
            |
            | If a manually triggered job fails and Laravel retries it,
            | the synchronization still remains "manual".
            |
            */
            $table->enum('trigger', [
                'manual',
                'scheduler',
                'system',
            ])->default('system');

            /*
            |--------------------------------------------------------------------------
            | Synchronization Type
            |--------------------------------------------------------------------------
            |
            | full        → posts + comments + downstream processing
            | posts       → Facebook posts synchronization
            | comments    → Facebook comments synchronization
            | incremental → incremental synchronization
            |
            */
            $table->enum('type', [
                'full',
                'posts',
                'comments',
                'incremental',
            ])->default('posts');

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | queued    → run created and waiting for queue worker
            | running   → worker has started processing
            | completed → synchronization finished successfully
            | failed    → synchronization failed after retries/execution
            |
            */
            $table->enum('status', [
                'queued',
                'running',
                'completed',
                'failed',
            ])->default('queued');

            /*
            |--------------------------------------------------------------------------
            | Queue Information
            |--------------------------------------------------------------------------
            |
            | queue:
            |   Logical Laravel queue used for this synchronization.
            |
            | job_id:
            |   Laravel queue job identifier.
            |
            | job_id is diagnostic information only.
            | The application should use facebook_sync_runs.id as the
            | synchronization identifier.
            |
            */
            $table->string('queue', 100)
                ->default('facebook');

            $table->string('job_id', 191)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Execution Attempts
            |--------------------------------------------------------------------------
            |
            | Number of times the synchronization job has attempted execution.
            |
            */
            $table->unsignedTinyInteger('attempts')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Timing
            |--------------------------------------------------------------------------
            |
            | queued_at:
            |   When the synchronization run was created.
            |
            | started_at:
            |   When the queue worker actually started processing.
            |
            | completed_at:
            |   When the synchronization finished successfully.
            |
            */
            $table->timestamp('queued_at')
                ->useCurrent();

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('completed_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Post Statistics
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('posts_fetched')
                ->default(0);

            $table->unsignedInteger('posts_created')
                ->default(0);

            $table->unsignedInteger('posts_updated')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Comment Statistics
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('comments_fetched')
                ->default(0);

            $table->unsignedInteger('comments_created')
                ->default(0);

            $table->unsignedInteger('comments_updated')
                ->default(0);

            $table->unsignedInteger('comments_failed')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | AI Processing Statistics
            |--------------------------------------------------------------------------
            |
            | These counters represent downstream AI work associated with
            | this synchronization run.
            |
            */

            $table->unsignedInteger('posts_queued_for_ai')
                ->default(0);

            $table->unsignedInteger('comments_queued_for_ai')
                ->default(0);

            $table->unsignedInteger('ai_completed')
                ->default(0);

            $table->unsignedInteger('ai_failed')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Error Information
            |--------------------------------------------------------------------------
            |
            | Stores the final failure reason when the synchronization fails.
            |
            */
            $table->text('error_message')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Additional Metadata
            |--------------------------------------------------------------------------
            |
            | Useful for storing non-critical execution information without
            | continuously changing the schema.
            |
            | Example:
            |
            | {
            |     "graph_api_version": "v26.0",
            |     "pages_processed": 12,
            |     "pagination_pages": 15,
            |     "duration_seconds": 42
            | }
            |
            | Do NOT store access tokens or other secrets here.
            |
            */
            $table->json('metadata')
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

            /*
            | Page synchronization history.
            |
            | Useful for:
            |
            |   WHERE facebook_page_id = ?
            |   ORDER BY created_at DESC
            |
            */
            $table->index([
                'facebook_page_id',
                'created_at',
            ]);

            /*
            | Find currently queued/running synchronization runs
            | for a specific Facebook Page.
            */
            $table->index([
                'facebook_page_id',
                'status',
            ]);

            /*
            | Useful for synchronization history by user.
            */
            $table->index([
                'triggered_by_user_id',
                'created_at',
            ]);

            /*
            | Useful for administrative filtering by status.
            */
            $table->index('status');

            /*
            | Useful for filtering by trigger.
            */
            $table->index('trigger');

            /*
            | Useful for locating a Laravel queue job.
            */
            $table->index('job_id');

            /*
            | Useful for queue monitoring/filtering.
            */
            $table->index('queue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_sync_runs');
    }
};