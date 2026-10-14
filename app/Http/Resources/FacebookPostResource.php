<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookPostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * -----------------------------------------------------
             * IDENTITY
             * -----------------------------------------------------
             */

            /**
             * Internal Laravel UUID.
             */
            'id' => $this->id,

            /**
             * Internal Laravel FacebookPage UUID.
             *
             * References:
             *
             *     facebook_pages.id
             */
            'facebook_page_id' => $this->facebook_page_id,

            /**
             * External Meta/Facebook Post ID.
             */
            'facebook_post_id' => $this->facebook_post_id,

            /*
             * -----------------------------------------------------
             * CONTENT
             * -----------------------------------------------------
             */

            'message' => $this->message,

            'published_at' => $this->published_at?->toISOString(),

            'permalink_url' => $this->permalink_url,

            /*
             * -----------------------------------------------------
             * ATTACHMENTS
             * -----------------------------------------------------
             *
             * Only root-level attachments are returned here.
             *
             * Album/media children are returned through the
             * "children" property of each attachment.
             *
             * The controller should eager-load:
             *
             *     attachments.children
             */
            'attachments' => FacebookPostAttachmentResource::collection(
                $this->whenLoaded('attachments')
            ),

            /*
             * -----------------------------------------------------
             * ENGAGEMENT
             * -----------------------------------------------------
             *
             * These map directly to the facebook_posts database
             * columns:
             *
             *     like_count
             *     comment_count
             *     share_count
             */
            'engagement' => [
                'likes' => $this->like_count,
                'comments' => $this->comment_count,
                'shares' => $this->share_count,
            ],

            /*
             * -----------------------------------------------------
             * AI POST ANALYSIS
             * -----------------------------------------------------
             *
             * These values describe the AI analysis of the post
             * itself.
             */
            'ai_analysis' => [
                'sentiment' => [
                    'label' => $this->sentiment,
                    'score' => $this->sentiment_score,
                ],

                'topic' => [
                    'name' => $this->topic,
                    'confidence' => $this->topic_confidence,
                ],
            ],

            /*
             * -----------------------------------------------------
             * TOP COMMENTS
             * -----------------------------------------------------
             *
             * The controller/service should determine how many
             * comments are loaded.
             *
             * Do not claim "at most 5" here unless the relationship
             * is explicitly limited to 5.
             */
            'top_comments' => FacebookCommentResource::collection(
                $this->whenLoaded('comments')
            ),

            /*
             * -----------------------------------------------------
             * TIMESTAMPS
             * -----------------------------------------------------
             */

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}