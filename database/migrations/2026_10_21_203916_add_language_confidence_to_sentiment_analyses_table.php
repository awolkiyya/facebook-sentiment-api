<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add language detection confidence to sentiment analyses.
     */
    public function up(): void
    {
        Schema::table('sentiment_analyses', function (Blueprint $table): void {
            $table->decimal('language_confidence', 8, 6)
                ->nullable()
                ->after('language')
                ->comment('Language detection confidence score between 0 and 1');
        });
    }

    /**
     * Remove language detection confidence.
     */
    public function down(): void
    {
        Schema::table('sentiment_analyses', function (Blueprint $table): void {
            $table->dropColumn('language_confidence');
        });
    }
};