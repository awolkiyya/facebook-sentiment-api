<?php

namespace App\Modules\Insights\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostAiInsightResource extends JsonResource
{
    /**
     * Transform the AI insight into its API representation.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Insight identity
            |--------------------------------------------------------------------------
            */
            'id' => $this->id,
            'facebook_post_id' => $this->facebook_post_id,

            /*
            |--------------------------------------------------------------------------
            | Facebook post details
            |--------------------------------------------------------------------------
            |
            | These fields are returned only when the corresponding Eloquent
            | relationships have been eager-loaded by the query.
            |
            */
            'post' => $this->whenLoaded('facebookPost', function () {
                $post = $this->facebookPost;

                return [
                    'id' => $post->id,
                    'message' => $post->message,
                    'permalink_url' => $post->permalink_url,
                    'posted_at' => $post->created_time,

                    'page' => $post->relationLoaded('page')
                        && $post->page
                            ? [
                                'id' => $post->page->id,
                                'name' => $post->page->name,
                            ]
                            : null,
                ];
            }),

            /*
            |--------------------------------------------------------------------------
            | Insight language and generated content
            |--------------------------------------------------------------------------
            */
            'language' => $this->language,
            'summary' => $this->summary,
            'key_concern' => $this->key_concern,
            'key_findings' => $this->key_findings,
            'recommendations' => $this->recommendations,

            /*
            |--------------------------------------------------------------------------
            | Classification
            |--------------------------------------------------------------------------
            */
            'primary_topic' => $this->primary_topic,
            'sentiment' => $this->sentiment,
            'priority' => $this->priority,
            'actionable' => (bool) $this->actionable,

            /*
            |--------------------------------------------------------------------------
            | Analysis metrics
            |--------------------------------------------------------------------------
            */
            'analyzed_comments' => (int) $this->analyzed_comments,

            'confidence' => $this->confidence !== null
                ? (float) $this->confidence
                : null,

            /*
            |--------------------------------------------------------------------------
            | AI model information
            |--------------------------------------------------------------------------
            */
            'model' => [
                'name' => $this->model_name,
                'version' => $this->model_version,
                'analysis_version' => $this->analysis_version,
            ],

            /*
            |--------------------------------------------------------------------------
            | Processing status
            |--------------------------------------------------------------------------
            */
            'status' => $this->status,

            'error_message' => $this->when(
                $this->status === 'FAILED',
                $this->error_message,
            ),

            'generation_attempts' => (int) $this->generation_attempts,

            /*
            |--------------------------------------------------------------------------
            | Evidence and metadata
            |--------------------------------------------------------------------------
            */
            'evidence' => [
                'hash' => $this->evidence_hash,
                'version' => $this->evidence_version,
            ],

            'metadata' => $this->metadata,

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            'generated_at' => $this->generated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
