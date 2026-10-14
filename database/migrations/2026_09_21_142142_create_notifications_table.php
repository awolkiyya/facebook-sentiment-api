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
        Schema::create('notifications', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Notification Content
            |--------------------------------------------------------------------------
            */

            $table->string('title', 255);

            $table->text('message');

            /*
            |--------------------------------------------------------------------------
            | Classification
            |--------------------------------------------------------------------------
            |
            | type:
            | - announcement
            | - maintenance
            | - security
            | - policy
            | - system
            |
            | priority:
            | - normal
            | - important
            | - urgent
            |
            | status:
            | - draft
            | - scheduled
            | - published
            | - archived
            |
            */

            $table->string('type', 30)
                ->default('announcement')
                ->index();

            $table->string('priority', 20)
                ->default('normal')
                ->index();

            $table->string('status', 20)
                ->default('draft')
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Audience
            |--------------------------------------------------------------------------
            |
            | audience_type:
            | - all_users
            | - role
            | - selected_users
            |
            | audience_data examples:
            |
            | all_users:
            | null
            |
            | role:
            | {
            |     "roles": [
            |         "SYSTEM_ADMIN",
            |         "MAYOR"
            |     ]
            | }
            |
            | selected_users:
            | {
            |     "user_ids": [
            |         "uuid-1",
            |         "uuid-2"
            |     ]
            | }
            |
            */

            $table->string('audience_type', 30)
                ->default('all_users')
                ->index();

            $table->json('audience_data')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Publication Lifecycle
            |--------------------------------------------------------------------------
            */

            $table->timestamp('published_at')
                ->nullable()
                ->index();

            $table->timestamp('scheduled_at')
                ->nullable()
                ->index();

            $table->timestamp('expires_at')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Ownership
            |--------------------------------------------------------------------------
            */

            $table->uuid('created_by')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Audit / Soft Delete
            |--------------------------------------------------------------------------
            */

            $table->timestamps();

            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            |
            | Keep nullable because the administrator who created the
            | notification could later be deleted.
            |
            */

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};