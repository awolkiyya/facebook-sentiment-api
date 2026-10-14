<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_model_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('version');
            $table->string('model_name');

            $table->string('framework')->nullable();

            $table->decimal('accuracy', 8, 6)->nullable();
            $table->decimal('macro_f1', 8, 6)->nullable();

            $table->json('supported_languages')->nullable();

            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index('is_active');
        });

        DB::statement('
            CREATE UNIQUE INDEX language_model_versions_one_active_per_name
            ON language_model_versions (name)
            WHERE is_active = true
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('language_model_versions');
    }
};