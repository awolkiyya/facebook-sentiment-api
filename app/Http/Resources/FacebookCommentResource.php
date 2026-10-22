<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SentimentAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        /*
        |--------------------------------------------------------------------------
        | Latest Sentiment Analysis
        |--------------------------------------------------------------------------
        |
        | Only serialize the relationship when it has already been loaded.
        | This avoids additional database queries during serialization.
        |
        */

        $sentimentAnalysis = $this->resource->relationLoaded(
            'latestSentimentAnalysis',
        )
            ? $this->resource->getRelation('latestSentimentAnalysis')
            : null;

        /*
        |--------------------------------------------------------------------------
        | AI Analysis
        |--------------------------------------------------------------------------
        */

        $aiAnalysis = $sentimentAnalysis instanceof SentimentAnalysis
            ? [
                'sentiment' => [
                    'label' => $sentimentAnalysis->sentiment,
                    'confidence' => $sentimentAnalysis->confidence,
                ],

                'language' => $sentimentAnalysis->language,

                'language_confidence' =>
                    $sentimentAnalysis->language_confidence,

                'topic' => $sentimentAnalysis->topic,

                'topic_confidence' =>
                    $sentimentAnalysis->topic_confidence,

                'priority' => $sentimentAnalysis->priority,

                'priority_confidence' =>
                    $sentimentAnalysis->priority_confidence,
            ]
            : null;

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
            | Thread
            |--------------------------------------------------------------------------
            */

            'replies_count' => $this->whenCounted('replies'),

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
            | Engagement
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

            'ai_analysis' => $aiAnalysis,

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
