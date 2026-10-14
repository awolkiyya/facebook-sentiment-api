<?php

declare(strict_types=1);

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
        Schema::table(
            'sentiment_analyses',
            function (Blueprint $table): void {
                /*
                |--------------------------------------------------------------------------
                | Model Version
                |--------------------------------------------------------------------------
                |
                | Model-version management is not enabled in the current MVP.
                | Keep the column for future use, but allow NULL for now.
                |
                */
                $table->uuid('sentiment_model_version_id')
                    ->nullable()
                    ->change();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Current MVP Uniqueness
        |--------------------------------------------------------------------------
        |
        | For now, each Facebook comment has one current AI analysis.
        |
        | The previous index:
        |
        | facebook_comment_id + sentiment_model_version_id
        |
        | is not sufficient for the current architecture because the
        | model version may be NULL.
        |
        */
        Schema::table(
            'sentiment_analyses',
            function (Blueprint $table): void {
                $table->unique(
                    'facebook_comment_id',
                    'sentiment_analyses_facebook_comment_id_unique',
                );
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Remove MVP unique constraint
        |--------------------------------------------------------------------------
        */
        Schema::table(
            'sentiment_analyses',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'sentiment_analyses_facebook_comment_id_unique',
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Restore original model-version requirement
        |--------------------------------------------------------------------------
        |
        | This will only succeed if there are no NULL values.
        |
        */
        Schema::table(
            'sentiment_analyses',
            function (Blueprint $table): void {
                $table->uuid('sentiment_model_version_id')
                    ->nullable(false)
                    ->change();
            }
        );
    }
};
