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
        /*
        |--------------------------------------------------------------------------
        | Create table first
        |--------------------------------------------------------------------------
        |
        | The self-referencing foreign key is intentionally NOT created
        | inside this Schema::create() call.
        |
        */
        Schema::create('facebook_post_attachments', function (Blueprint $table) {

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
            */
            $table->foreignUuid('facebook_post_id')
                ->constrained('facebook_posts')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Parent Attachment
            |--------------------------------------------------------------------------
            |
            | Self-referencing UUID.
            |
            | NULL = root attachment.
            | UUID = child attachment.
            |
            */
            $table->uuid('parent_attachment_id')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Meta Attachment Type
            |--------------------------------------------------------------------------
            */
            $table->string('type', 100)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Normalized Media Type
            |--------------------------------------------------------------------------
            */
            $table->string('media_type', 50)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | URLs
            |--------------------------------------------------------------------------
            */
            $table->text('url')
                ->nullable();

            $table->text('media_url')
                ->nullable();

            $table->text('thumbnail_url')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Target
            |--------------------------------------------------------------------------
            */
            $table->string('target_id', 191)
                ->nullable();

            $table->text('target_url')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */
            $table->string('title', 1000)
                ->nullable();

            $table->text('description')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Dimensions
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('width')
                ->nullable();

            $table->unsignedInteger('height')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Position
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('position')
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
                'facebook_post_id',
                'position',
            ]);

            $table->index([
                'facebook_post_id',
                'type',
            ]);

            $table->index('target_id');

            $table->index('media_type');

            $table->index('parent_attachment_id');
        });

        /*
        |--------------------------------------------------------------------------
        | Self-Referencing Foreign Key
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | This is deliberately created AFTER the table already exists.
        |
        */
        Schema::table('facebook_post_attachments', function (Blueprint $table) {
            $table->foreign('parent_attachment_id')
                ->references('id')
                ->on('facebook_post_attachments')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_post_attachments');
    }
};