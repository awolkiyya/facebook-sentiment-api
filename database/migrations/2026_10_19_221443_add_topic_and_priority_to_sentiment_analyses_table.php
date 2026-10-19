<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentiment_analyses', function (Blueprint $table) {
            $table->string('topic', 150)->nullable();
            $table->decimal('topic_confidence', 8, 6)->nullable();

            $table->string('priority', 50)->nullable();
            $table->decimal('priority_confidence', 8, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sentiment_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'topic',
                'topic_confidence',
                'priority',
                'priority_confidence',
            ]);
        });
    }
};