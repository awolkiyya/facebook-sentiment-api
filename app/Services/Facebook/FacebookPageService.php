<?php

declare(strict_types=1);

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookPageService
{
    public function __construct(
        private readonly FacebookOAuthService $facebookOAuthService
    ) {
    }

    /**
     * Get all Facebook Pages connected to a local user.
     *
     * A user may have multiple connected Pages.
     */
    public function getUserPages(
        string $userId
    ): Collection {
        return FacebookPage::query()
            ->where('connected_by_user_id', $userId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get([
                'id',
                'facebook_page_id',
                'name',
                'category',
                'category_list',
                'followers_count',
                'is_active',
                'page_access_token_expires_at',
                'last_synced_at',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * Get one Facebook Page belonging to a local user.
     *
     * IMPORTANT:
     *
     * $facebookPageId is the external Meta/Facebook Page ID,
     * NOT the internal Laravel UUID.
     *
     * This ownership check prevents IDOR / cross-user Page access.
     */
    public function getUserPage(
        string $userId,
        string $facebookPageId
    ): FacebookPage {
        return FacebookPage::query()
            ->where('connected_by_user_id', $userId)
            ->where('facebook_page_id', $facebookPageId)
            ->firstOrFail();
    }

    /**
     * Enable or disable monitoring for a Facebook Page.
     *
     * Multiple Pages may be monitored simultaneously.
     */
    public function updateMonitoring(
        FacebookPage $facebookPage,
        bool $isActive
    ): FacebookPage {
        $facebookPage->update([
            'is_active' => $isActive,
        ]);

        Log::info(
            'Facebook Page monitoring status updated.',
            [
                'facebook_page_database_id' => (string) $facebookPage->id,
                'facebook_page_id' => (string) $facebookPage->facebook_page_id,
                'is_active' => $isActive,
            ]
        );

        return $facebookPage->refresh();
    }

    /**
     * Synchronize Facebook Page metadata from Meta.
     *
     * Updates:
     * - name
     * - category
     * - category_list
     * - followers_count
     * - last_synced_at
     */
    public function syncMetadata(
        FacebookPage $facebookPage
    ): FacebookPage {
        /*
         * The encrypted cast automatically decrypts the Page
         * Access Token when accessed.
         */
        $pageAccessToken =
            $facebookPage->access_token_encrypted;

        if (
            !is_string($pageAccessToken)
            || trim($pageAccessToken) === ''
        ) {
            throw new RuntimeException(
                'Facebook Page Access Token is not available.'
            );
        }

        try {
            $pageData =
                $this->facebookOAuthService->getPage(
                    pageId:
                        $facebookPage->facebook_page_id,
                    pageAccessToken:
                        $pageAccessToken,
                    fields: [
                        'id',
                        'name',
                        'category',
                        'category_list',
                        'followers_count',
                    ],
                );

            /*
             * Verify that Meta returned metadata for the
             * Page we actually requested.
             */
            if (
                isset($pageData['id'])
                && (string) $pageData['id']
                    !== (string) $facebookPage->facebook_page_id
            ) {
                throw new RuntimeException(
                    'Facebook returned metadata for an unexpected Page.'
                );
            }

            $updateData = [
                'last_synced_at' => now(),
            ];

            /*
             * Page name.
             */
            if (
                isset($pageData['name'])
                && is_string($pageData['name'])
                && trim($pageData['name']) !== ''
            ) {
                $updateData['name'] =
                    $pageData['name'];
            }

            /*
             * Page category.
             */
            if (
                array_key_exists(
                    'category',
                    $pageData
                )
            ) {
                $updateData['category'] =
                    is_string(
                        $pageData['category']
                    )
                        ? $pageData['category']
                        : null;
            }

            /*
             * Page category list.
             */
            if (
                array_key_exists(
                    'category_list',
                    $pageData
                )
            ) {
                $updateData['category_list'] =
                    is_array(
                        $pageData['category_list']
                    )
                        ? $pageData['category_list']
                        : [];
            }

            /*
             * Page followers count.
             */
            if (
                array_key_exists(
                    'followers_count',
                    $pageData
                )
            ) {
                $followersCount =
                    $pageData['followers_count'];

                if (
                    $followersCount !== null
                    && !is_numeric($followersCount)
                ) {
                    throw new RuntimeException(
                        'Facebook returned an invalid followers count.'
                    );
                }

                $updateData['followers_count'] =
                    $followersCount !== null
                        ? (int) $followersCount
                        : null;
            }

            $facebookPage->update(
                $updateData
            );

            Log::info(
                'Facebook Page metadata synchronized.',
                [
                    'facebook_page_database_id' =>
                        (string) $facebookPage->id,

                    'facebook_page_id' =>
                        (string) $facebookPage->facebook_page_id,

                    'followers_count' =>
                        $updateData['followers_count']
                            ?? null,
                ]
            );

            return $facebookPage->refresh();

        } catch (Throwable $exception) {
            /*
             * Do NOT log the exception message here.
             *
             * HTTP exceptions can contain a complete Facebook
             * request URL, potentially exposing an access token.
             */
            Log::error(
                'Facebook Page metadata synchronization failed.',
                [
                    'facebook_page_database_id' =>
                        (string) $facebookPage->id,

                    'facebook_page_id' =>
                        (string) $facebookPage->facebook_page_id,

                    'exception' =>
                        get_class($exception),
                ]
            );

            throw $exception;
        }
    }

    /**
     * Synchronize all active Facebook Pages belonging to a user.
     *
     * One failed Page does not prevent other Pages from syncing.
     */
    public function syncActivePages(
        string $userId
    ): int {
        $pages = FacebookPage::query()
            ->where(
                'connected_by_user_id',
                $userId
            )
            ->where('is_active', true)
            ->get();

        $syncedCount = 0;

        foreach ($pages as $facebookPage) {
            try {
                $this->syncMetadata(
                    $facebookPage
                );

                $syncedCount++;
            } catch (Throwable $exception) {
                /*
                 * Do not log the exception message.
                 *
                 * It may contain a Facebook request URL
                 * containing an access token.
                 */
                Log::warning(
                    'Failed to synchronize one Facebook Page while syncing user Pages.',
                    [
                        'user_id' =>
                            $userId,

                        'facebook_page_id' =>
                            (string) $facebookPage->facebook_page_id,

                        'exception' =>
                            get_class($exception),
                    ]
                );
            }
        }

        return $syncedCount;
    }

    /**
     * Disconnect a Facebook Page from the local user.
     *
     * This removes the local connection and encrypted
     * Page token.
     */
    public function disconnect(
        FacebookPage $facebookPage
    ): void {
        $databaseId =
            (string) $facebookPage->id;

        $facebookPageId =
            (string) $facebookPage->facebook_page_id;

        DB::transaction(
            function () use ($facebookPage): void {
                $facebookPage->delete();
            }
        );

        Log::info(
            'Facebook Page disconnected.',
            [
                'facebook_page_database_id' =>
                    $databaseId,

                'facebook_page_id' =>
                    $facebookPageId,
            ]
        );
    }
}