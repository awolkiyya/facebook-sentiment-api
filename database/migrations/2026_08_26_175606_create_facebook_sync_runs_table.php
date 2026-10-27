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
            | Application-level synchronization run identifier.
            | This is independent of Laravel's queue job identifier.
            |
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Page
            |--------------------------------------------------------------------------
            |
            | References the local facebook_pages.id UUID,
            | not the external Meta/Facebook Page ID.
            |
            | Page deletion cascades to synchronization history.
            | If audit retention is required after disconnection, change
            | this deletion policy before deploying the migration.
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
            | NULL for scheduler/system-triggered runs.
            | Set for manually initiated runs.
            |
            */
            $table->foreignUuid('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Trigger
            |--------------------------------------------------------------------------
            |
            | manual    = explicitly requested by a user
            | scheduler = initiated by an automated schedule
            | system    = initiated by internal application logic
            |
            | Retries do not change the original trigger.
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
            | full        = posts, comments, and downstream processing
            | posts       = post synchronization
            | comments    = comment synchronization
            | incremental = incremental synchronization
            |
            | Actual behavior must be implemented by the corresponding jobs.
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
            | Synchronization Status
            |--------------------------------------------------------------------------
            |
            | queued    = waiting for execution
            | running   = synchronization job is executing
            | completed = synchronization work completed successfully
            | failed    = synchronization reached terminal failure
            |
            | IMPORTANT:
            | completed does not necessarily mean all downstream AI jobs
            | have completed. AI processing is tracked separately.
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
            | queue  = Laravel queue assigned to this synchronization job
            | job_id = Laravel queue job identifier, when available
            |
            | facebook_sync_runs.id remains the application-level run ID.
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
            | Number of execution attempts recorded by the application.
            | Must be updated explicitly by the job/tracking logic.
            |
            */
            $table->unsignedInteger('attempts')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Lifecycle Timestamps
            |--------------------------------------------------------------------------
            |
            | queued_at          = when the run was created/queued
            | started_at         = when execution first started
            | last_heartbeat_at  = last recorded execution progress
            | completed_at       = successful completion time
            | finished_at        = terminal completion or failure time
            |
            | Heartbeats must be maintained by the running job.
            | These fields do not update automatically.
            |
            */
            $table->timestamp('queued_at')
                ->useCurrent();

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('last_heartbeat_at')
                ->nullable();

            $table->timestamp('completed_at')
                ->nullable();

            $table->timestamp('finished_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Post Synchronization Statistics
            |--------------------------------------------------------------------------
            |
            | Define fetched as records retrieved from Meta.
            | Created/updated represent local persistence results.
            |
            */
            $table->unsignedInteger('posts_fetched')
                ->default(0);

            $table->unsignedInteger('posts_created')
                ->default(0);

            $table->unsignedInteger('posts_updated')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Comment Synchronization Statistics
            |--------------------------------------------------------------------------
            |
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
            | AI Dispatch Statistics
            |--------------------------------------------------------------------------
            |
            | Count of unique post/comment analysis tasks dispatched for
            | this run, according to the application's counting policy.
            |
            | These are not counts of posts/comments fetched.
            |
            */
            $table->unsignedInteger('posts_queued_for_ai')
                ->default(0);

            $table->unsignedInteger('comments_queued_for_ai')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | AI Processing Outcomes
            |--------------------------------------------------------------------------
            |
            | Track final task outcomes, not every intermediate retry.
            |
            | These counters can be updated after the synchronization job
            | finishes, because AI jobs may execute asynchronously.
            |
            | Use atomic/idempotent updates to avoid double counting.
            |
            */
            $table->unsignedInteger('ai_completed')
                ->default(0);

            $table->unsignedInteger('ai_failed')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Synchronization Error
            |--------------------------------------------------------------------------
            |
            | Store a sanitized final synchronization error.
            | Avoid access tokens, credentials, and sensitive payloads.
            |
            */
            $table->text('error_message')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Diagnostic Metadata
            |--------------------------------------------------------------------------
            |
            | Supplemental non-sensitive execution details.
            |
            | Example:
            | {
            |     "graph_api_version": "v26.0",
            |     "pagination_pages": 15,
            |     "duration_seconds": 42
            | }
            |
            | Keep frequently queried values in dedicated columns.
            |
            */
            $table->json('metadata')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Record Timestamps
            |--------------------------------------------------------------------------
            */
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            // Page synchronization history.
            $table->index(
                ['facebook_page_id', 'created_at'],
                'fsr_page_created_idx'
            );

            // Filter and inspect runs by Page and status.
            $table->index(
                ['facebook_page_id', 'status'],
                'fsr_page_status_idx'
            );

            // User synchronization history.
            $table->index(
                ['triggered_by_user_id', 'created_at'],
                'fsr_user_created_idx'
            );

            // Administrative status filtering.
            $table->index(
                'status',
                'fsr_status_idx'
            );

            // Filter by manual/scheduled/system trigger.
            $table->index(
                'trigger',
                'fsr_trigger_idx'
            );

            // Queue diagnostics.
            $table->index(
                'job_id',
                'fsr_job_id_idx'
            );

            $table->index(
                'queue',
                'fsr_queue_idx'
            );

            // Find old queued runs.
            $table->index(
                ['status', 'queued_at'],
                'fsr_status_queued_idx'
            );

            // Find potentially stalled running jobs.
            $table->index(
                ['status', 'last_heartbeat_at'],
                'fsr_status_heartbeat_idx'
            );
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