<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sentiment_model_versions', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Model Identity
            |--------------------------------------------------------------------------
            */
            $table->string('name');

            $table->string('version');

            $table->string('model_name');

            /*
            |--------------------------------------------------------------------------
            | Framework
            |--------------------------------------------------------------------------
            */
            $table->string('framework')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Evaluation Metrics
            |--------------------------------------------------------------------------
            */
            $table->decimal('accuracy', 8, 6)
                ->nullable();

            $table->decimal('macro_f1', 8, 6)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Supported Languages
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | ["am", "om", "en"]
            |
            */
            $table->json('supported_languages')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Activation
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_active')
                ->default(false);

            $table->timestamp('activated_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Constraints / Indexes
            |--------------------------------------------------------------------------
            */
            $table->unique([
                'name',
                'version',
            ]);

            $table->index('is_active');
        });

        /*
        |--------------------------------------------------------------------------
        | One active version per model name
        |--------------------------------------------------------------------------
        |
        | Matches the partial unique index already present on
        | language_model_versions, priority_model_versions and
        | topic_model_versions: across rows sharing the same `name`, at
        | most one can have is_active = true.
        */
        DB::statement('
            CREATE UNIQUE INDEX sentiment_model_versions_one_active_per_name
            ON sentiment_model_versions (name)
            WHERE is_active = true
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentiment_model_versions');
    }
};