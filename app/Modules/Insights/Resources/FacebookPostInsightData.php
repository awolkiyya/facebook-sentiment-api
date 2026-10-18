<?php

namespace App\Modules\Insights\Resources;

final readonly class FacebookPostInsightData
{
    public function __construct(
        public string $postId,
        public string $language,
        public ?string $dateFrom,
        public ?string $dateTo,
        public array $metrics,
        public string $summary,
        public array $keyFindings,
        public array $recommendations,
        public string $generatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'post_id' => $this->postId,
            'language' => $this->language,

            'date_range' => [
                'from' => $this->dateFrom,
                'to' => $this->dateTo,
            ],

            'metrics' => $this->metrics,
            'summary' => $this->summary,
            'key_findings' => $this->keyFindings,
            'recommendations' => $this->recommendations,
            'generated_at' => $this->generatedAt,
        ];
    }
}