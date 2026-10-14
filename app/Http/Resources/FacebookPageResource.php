<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookPageResource extends JsonResource
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
            | Internal Identity
            |--------------------------------------------------------------------------
            */
            'id' => $this->id,

            /*
            |--------------------------------------------------------------------------
            | Facebook Identity
            |--------------------------------------------------------------------------
            */
            'facebook_page_id' => $this->facebook_page_id,

            'name' => $this->name,

            /*
            |--------------------------------------------------------------------------
            | Page Classification
            |--------------------------------------------------------------------------
            */
            'category' => $this->category,

            'category_list' => $this->category_list ?? [],

            /*
            |--------------------------------------------------------------------------
            | Audience
            |--------------------------------------------------------------------------
            |
            | This is the latest follower count synchronized from Meta.
            |
            */
            'followers_count' => $this->followers_count,

            /*
            |--------------------------------------------------------------------------
            | Monitoring
            |--------------------------------------------------------------------------
            |
            | is_active means this Page is currently included in the
            | Facebook intelligence/monitoring pipeline.
            |
            */
            'is_active' => (bool) $this->is_active,

            'monitoring' => [
                'enabled' => (bool) $this->is_active,

                'status' => $this->is_active
                    ? 'monitoring'
                    : 'not_monitoring',
            ],

            /*
            |--------------------------------------------------------------------------
            | Synchronization
            |--------------------------------------------------------------------------
            */
            'sync' => [
                'last_synced_at' =>
                    $this->last_synced_at?->toISOString(),

                'status' => $this->resolveSyncStatus(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Token Information
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Never expose:
            |
            | - access_token_encrypted
            | - decrypted access token
            | - Facebook OAuth credentials
            |
            */
            'connection' => [
                'status' => 'connected',

                'token_expires_at' =>
                    $this->token_expires_at?->toISOString(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Resolve the current synchronization status.
     */
    private function resolveSyncStatus(): string
    {
        /*
         * A Page that has never been synchronized is connected,
         * but metadata has not yet been synchronized.
         */
        if (!$this->last_synced_at) {
            return 'pending';
        }

        /*
         * If the last synchronization is reasonably recent,
         * consider the metadata synchronized.
         */
        if (
            $this->last_synced_at->greaterThanOrEqualTo(
                now()->subHours(24)
            )
        ) {
            return 'synced';
        }

        /*
         * Older metadata should be refreshed.
         */
        return 'stale';
    }
}