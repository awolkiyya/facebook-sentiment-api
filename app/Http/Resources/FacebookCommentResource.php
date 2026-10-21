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
            |
            | Return only metrics actually collected and persisted.
            |
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
                    'language' => $sentimentAnalysis->language,
                    'language_confidence' => $sentimentAnalysis->language_confidence,

                    /*
                     * Civic topic classification
                     *
                     * Topic values are supplied by the AI service.
                     * Laravel does not enforce a fixed topic vocabulary.
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