<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $sentimentAnalysis = $this->latestSentimentAnalysis;

        return [
            /*
             * -----------------------------------------------------
             * IDENTITY
             * -----------------------------------------------------
             */

            'id' => $this->id,

            'facebook_comment_id' => $this->facebook_comment_id,

            'parent_comment_id' => $this->parent_comment_id,

            /*
             * -----------------------------------------------------
             * AUTHOR
             * -----------------------------------------------------
             */

            'author' => [
                'name' => $this->author_name,
                'facebook_id' => $this->author_facebook_id,
            ],

            /*
             * -----------------------------------------------------
             * CONTENT
             * -----------------------------------------------------
             */

            'message' => $this->message,

            'permalink_url' => $this->permalink_url,

            'commented_at' => $this->commented_at?->toISOString(),

            /*
             * -----------------------------------------------------
             * ENGAGEMENT
             * -----------------------------------------------------
             */

            'engagement' => [
                'likes' => $this->like_count,
            ],

            /*
             * -----------------------------------------------------
             * AI ANALYSIS
             * -----------------------------------------------------
             */

            'ai_analysis' => $sentimentAnalysis
                ? [
                    'sentiment' => [
                        'label' =>
                            $sentimentAnalysis->sentiment,

                        'confidence' =>
                            $sentimentAnalysis->confidence,

                        'scores' => [
                            'positive' =>
                                $sentimentAnalysis->positive_score,

                            'neutral' =>
                                $sentimentAnalysis->neutral_score,

                            'negative' =>
                                $sentimentAnalysis->negative_score,
                        ],
                    ],

                    'language' =>
                        $sentimentAnalysis->language,

                    'processing_time_ms' =>
                        $sentimentAnalysis->processing_time_ms,

                    'analyzed_at' =>
                        $sentimentAnalysis->analyzed_at
                            ?->toISOString(),

                    'sentiment_model_version_id' =>
                        $sentimentAnalysis
                            ->sentiment_model_version_id,
                ]
                : null,

            /*
             * -----------------------------------------------------
             * TIMESTAMPS
             * -----------------------------------------------------
             */

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}