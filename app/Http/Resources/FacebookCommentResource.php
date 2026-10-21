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
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            'facebook_comment_id' => $this->facebook_comment_id,

            'parent_comment_id' => $this->parent_comment_id,

            /*
            |--------------------------------------------------------------------------
            | Author
            |--------------------------------------------------------------------------
            */

            'author' => [
                'name' => $this->author_name,
                'facebook_id' => $this->author_facebook_id,
            ],

            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            */

            'message' => $this->message,

            'permalink_url' => $this->permalink_url,

            'commented_at' => $this->commented_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Comment Engagement
            |--------------------------------------------------------------------------
            */

            'engagement' => [
                'like_count' => $this->like_count,
            ],

            /*
            |--------------------------------------------------------------------------
            | AI Analysis
            |--------------------------------------------------------------------------
            */

            'ai_analysis' => $sentimentAnalysis
                ? [
                    /*
                     * Sentiment classification
                     */
                    'sentiment' => [
                        'label' => $sentimentAnalysis->sentiment,
                        'confidence' => $sentimentAnalysis->confidence,
                    ],

                    /*
                     * Language detection
                     */
                    'language' => [
                        'code' => $sentimentAnalysis->language,
                        'confidence' => $sentimentAnalysis->language_confidence,
                    ],

                    /*
                     * Civic topic classification
                     */
                    'topic' => $sentimentAnalysis->topic,

                    'topic_confidence' =>
                        $sentimentAnalysis->topic_confidence,

                    /*
                     * Priority classification
                     */
                    'priority' => $sentimentAnalysis->priority,

                    'priority_confidence' =>
                        $sentimentAnalysis->priority_confidence,
                ]
                : null,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
