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
        Schema::create('sentiment_analyses', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Facebook Comment
            |--------------------------------------------------------------------------
            */
            $table->foreignUuid('facebook_comment_id')
                ->constrained('facebook_comments')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | AI Model Version
            |--------------------------------------------------------------------------
            */
            $table->foreignUuid('sentiment_model_version_id')
                ->constrained('sentiment_model_versions')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Detected Language
            |--------------------------------------------------------------------------
            |
            | am = Amharic
            | om = Afaan Oromo
            | en = English
            |
            */
            $table->string('language', 10);

            /*
            |--------------------------------------------------------------------------
            | Sentiment
            |--------------------------------------------------------------------------
            */
            $table->enum('sentiment', [
                'positive',
                'neutral',
                'negative',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Overall Confidence
            |--------------------------------------------------------------------------
            */
            $table->decimal('confidence', 8, 6);

            /*
            |--------------------------------------------------------------------------
            | Class Probabilities
            |--------------------------------------------------------------------------
            */
            $table->decimal('positive_score', 8, 6)
                ->nullable();

            $table->decimal('neutral_score', 8, 6)
                ->nullable();

            $table->decimal('negative_score', 8, 6)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Processing Time
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('processing_time_ms')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Analysis Date
            |--------------------------------------------------------------------------
            */
            $table->timestamp('analyzed_at');

            /*
            |--------------------------------------------------------------------------
            | Raw AI Prediction
            |--------------------------------------------------------------------------
            */
            $table->json('raw_prediction')
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
                'sentiment',
                'language',
            ]);

            $table->index('analyzed_at');

            $table->index([
                'facebook_comment_id',
                'sentiment_model_version_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentiment_analyses');
    }
};