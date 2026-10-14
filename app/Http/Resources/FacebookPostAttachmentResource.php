<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookPostAttachmentResource extends JsonResource
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

            'id' => $this->id,

            /*
             * -----------------------------------------------------
             * ATTACHMENT TYPE
             * -----------------------------------------------------
             */

            /**
             * Original Meta attachment type.
             *
             * Examples:
             *
             * photo
             * album
             * video_inline
             * video_direct_response
             * share
             * native_templates
             */
            'type' => $this->type,

            /**
             * Application-normalized media type.
             *
             * Examples:
             *
             * image
             * video
             * album
             * link
             * unavailable
             * unknown
             */
            'media_type' => $this->media_type,

            /*
             * -----------------------------------------------------
             * MEDIA
             * -----------------------------------------------------
             */

            'url' => $this->url,

            'media_url' => $this->media_url,

            'thumbnail_url' => $this->thumbnail_url,

            /*
             * -----------------------------------------------------
             * TARGET
             * -----------------------------------------------------
             */

            'target' => [
                'id' => $this->target_id,
                'url' => $this->target_url,
            ],

            /*
             * -----------------------------------------------------
             * METADATA
             * -----------------------------------------------------
             */

            'title' => $this->title,

            'description' => $this->description,

            /*
             * -----------------------------------------------------
             * DIMENSIONS
             * -----------------------------------------------------
             */

            'width' => $this->width,

            'height' => $this->height,

            /*
             * -----------------------------------------------------
             * ORDER
             * -----------------------------------------------------
             */

            'position' => $this->position,

            /*
             * -----------------------------------------------------
             * NESTED ATTACHMENTS
             * -----------------------------------------------------
             *
             * Albums can contain subattachments.
             */
            'children' => FacebookPostAttachmentResource::collection(
                $this->whenLoaded('children')
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