<?php

namespace App\Modules\Administration\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Content
            |--------------------------------------------------------------------------
            */

            'title' => $this->title,

            'message' => $this->message,

            /*
            |--------------------------------------------------------------------------
            | Classification
            |--------------------------------------------------------------------------
            */

            'type' => $this->type,

            'priority' => $this->priority,

            'status' => $this->status,

            /*
            |--------------------------------------------------------------------------
            | Audience
            |--------------------------------------------------------------------------
            */

            'audience' => [
                'type' => $this->audience_type,

                'data' => $this->audience_data,
            ],

            /*
            |--------------------------------------------------------------------------
            | Recipients
            |--------------------------------------------------------------------------
            |
            | `recipients_count` comes from withCount('recipients').
            |
            */

            'recipient_count' => $this->when(
                isset($this->recipients_count),
                fn () => (int) $this->recipients_count
            ),

            /*
            |--------------------------------------------------------------------------
            | Creator
            |--------------------------------------------------------------------------
            */

            'created_by' => $this->created_by,

            'creator' => $this->when(
                $this->relationLoaded('creator'),
                function () {
                    return $this->creator
                        ? [
                            'id' => $this->creator->id,
                            'name' => $this->creator->name,
                        ]
                        : null;
                }
            ),

            /*
            |--------------------------------------------------------------------------
            | Lifecycle
            |--------------------------------------------------------------------------
            */

            'published_at' => $this->published_at?->toISOString(),

            'scheduled_at' => $this->scheduled_at?->toISOString(),

            'expires_at' => $this->expires_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),

            /*
            |--------------------------------------------------------------------------
            | Computed State
            |--------------------------------------------------------------------------
            */

            'is_expired' => $this->isExpired(),

            'is_scheduled_for_future' =>
                $this->isScheduledForFuture(),

            'is_publishable' =>
                $this->isPublishable(),
        ];
    }
}