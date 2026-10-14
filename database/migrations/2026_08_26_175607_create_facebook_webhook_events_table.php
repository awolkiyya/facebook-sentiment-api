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
        Schema::create('facebook_webhook_events', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Meta Event Identity
            |--------------------------------------------------------------------------
            */
            $table->string('event_id')
                ->nullable()
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Event Information
            |--------------------------------------------------------------------------
            */
            $table->string('object_type')
                ->nullable();

            $table->string('event_type')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Original Webhook Payload
            |--------------------------------------------------------------------------
            */
            $table->json('payload');

            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'received',
                'processed',
                'failed',
            ])->default('received');

            /*
            |--------------------------------------------------------------------------
            | Processing Dates
            |--------------------------------------------------------------------------
            */
            $table->timestamp('received_at');

            $table->timestamp('processed_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Error
            |--------------------------------------------------------------------------
            */
            $table->text('error_message')
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
                'event_type',
                'status',
            ]);

            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_webhook_events');
    }
};