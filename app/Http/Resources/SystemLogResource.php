<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Primary
            |--------------------------------------------------------------------------
            */

            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Actor
            |--------------------------------------------------------------------------
            */

            'user' => $this->when(
                $this->relationLoaded('user'),
                function () {
                    if (!$this->user) {
                        return null;
                    }

                    return [
                        'id' => $this->user->id,
                        'name' => $this->user->name,
                        'email' => $this->user->email,
                    ];
                }
            ),

            'user_id' => $this->user_id,

            /*
            |--------------------------------------------------------------------------
            | Audit Information
            |--------------------------------------------------------------------------
            */

            'action' => $this->action,

            'module' => $this->module,

            'status' => $this->status,

            'severity' => $this->severity,

            'description' => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Resource
            |--------------------------------------------------------------------------
            */

            'resource' => $this->resource_type
                ? [
                    'type' => $this->resource_type,
                    'id' => $this->resource_id,
                ]
                : null,

            'resource_type' => $this->resource_type,

            'resource_id' => $this->resource_id,

            /*
            |--------------------------------------------------------------------------
            | Change Tracking
            |--------------------------------------------------------------------------
            */

            'old_values' => $this->old_values,

            'new_values' => $this->new_values,

            /*
            |--------------------------------------------------------------------------
            | Request Information
            |--------------------------------------------------------------------------
            */

            'request_id' => $this->request_id,

            'http_method' => $this->http_method,

            'endpoint' => $this->endpoint,

            'ip_address' => $this->ip_address,

            'user_agent' => $this->user_agent,

            /*
            |--------------------------------------------------------------------------
            | Performance
            |--------------------------------------------------------------------------
            */

            'duration_ms' => $this->duration_ms,

            /*
            |--------------------------------------------------------------------------
            | Additional Context
            |--------------------------------------------------------------------------
            */

            'metadata' => $this->metadata,

            /*
            |--------------------------------------------------------------------------
            | Timestamp
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
