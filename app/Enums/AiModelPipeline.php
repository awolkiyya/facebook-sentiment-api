<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LanguageModelVersion;
use App\Models\PriorityModelVersion;
use App\Models\SentimentModelVersion;
use App\Models\TopicModelVersion;
use Illuminate\Database\Eloquent\Builder;

enum AiModelPipeline: string
{
    case LANGUAGE = 'language';
    case SENTIMENT = 'sentiment';
    case PRIORITY = 'priority';
    case TOPIC = 'topic';

    /**
     * Human-readable label for the pipeline.
     */
    public function label(): string
    {
        return match ($this) {
            self::LANGUAGE => 'Language',
            self::SENTIMENT => 'Sentiment',
            self::PRIORITY => 'Priority',
            self::TOPIC => 'Topic',
        };
    }

    /**
     * Description of what this pipeline does.
     */
    public function description(): string
    {
        return match ($this) {
            self::LANGUAGE =>
                'Identifies the language of incoming municipal comments.',

            self::SENTIMENT =>
                'Classifies comments as positive, neutral or negative.',

            self::PRIORITY =>
                'Flags comments that need urgent municipal attention.',

            self::TOPIC =>
                'Identifies the main municipal service or discussion topic.',
        };
    }

    /**
     * Resolve the Eloquent model used by this pipeline.
     */
    public function model(): string
    {
        return match ($this) {
            self::LANGUAGE => LanguageModelVersion::class,
            self::SENTIMENT => SentimentModelVersion::class,
            self::PRIORITY => PriorityModelVersion::class,
            self::TOPIC => TopicModelVersion::class,
        };
    }

    /**
     * Create a new Eloquent query for this pipeline.
     */
    public function newQuery(): Builder
    {
        return ($this->model())::query();
    }

    /**
     * Get all supported pipeline values.
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $pipeline) => $pipeline->value,
            self::cases(),
        );
    }
}