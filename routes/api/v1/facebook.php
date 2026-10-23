<?php

use App\Http\Controllers\Api\Facebook\FacebookAuthController;
use App\Http\Controllers\Api\Facebook\FacebookCommentController;
use App\Http\Controllers\Api\Facebook\FacebookPageController;
use App\Http\Controllers\Api\Facebook\FacebookPostController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facebook API Routes
|--------------------------------------------------------------------------
|
| Base URL:
|     /api/v1/facebook
|
| This route file contains:
|
| - Facebook OAuth
| - Connected Facebook Page management
| - Facebook Page posts
| - Facebook Post comments
|
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Facebook
|--------------------------------------------------------------------------
*/

Route::prefix('facebook')->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    */

    /*
     * Start Facebook OAuth.
     *
     * Requires an authenticated local Laravel user.
     *
     * GET /api/v1/facebook/auth/redirect
     */
    Route::middleware('auth:sanctum')->group(function (): void {

        Route::get(
            '/auth/redirect',
            [FacebookAuthController::class, 'redirect']
        )->name('facebook.auth.redirect');
    });

    /*
     * Facebook OAuth callback.
     *
     * IMPORTANT:
     *
     * This endpoint must remain public because Meta redirects the
     * user's browser directly back to this URL after authorization.
     *
     * The OAuth state stored by FacebookAuthController is responsible
     * for securely associating the callback with the local user.
     *
     * GET /api/v1/facebook/auth/callback
     */
    Route::get(
        '/auth/callback',
        [FacebookAuthController::class, 'callback']
    )->name('facebook.auth.callback');


    /*
    |--------------------------------------------------------------------------
    | Connected Facebook Pages
    |--------------------------------------------------------------------------
    |
    | All Page, Post, and Comment endpoints require an authenticated
    | local Laravel user.
    |
    | IMPORTANT ID CONVENTION
    |
    | {facebookPageId}
    |     = External Meta/Facebook Page ID
    |
    |     Example:
    |         100297668388012
    |
    | {facebookPostId}
    |     = Internal Laravel FacebookPost UUID
    |
    |     Example:
    |         01a0cb11-8753-72c6-9d76-1bf2eb4b7425
    |
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')
        ->prefix('pages')
        ->group(function (): void {

            /*
            |--------------------------------------------------------------------------
            | Page Management
            |--------------------------------------------------------------------------
            */

            /*
             * Get all Facebook Pages connected by the authenticated user.
             *
             * GET /api/v1/facebook/pages
             */
            Route::get(
                '/',
                [FacebookPageController::class, 'index']
            )->name('facebook.pages.index');


            /*
             * Enable or disable monitoring for a Facebook Page.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * PATCH /api/v1/facebook/pages/{facebookPageId}/monitoring
             */
            Route::patch(
                '/{facebookPageId}/monitoring',
                [FacebookPageController::class, 'updateMonitoring']
            )->name('facebook.pages.monitoring');


            /*
             * Synchronize Facebook Page metadata from Meta.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * POST /api/v1/facebook/pages/{facebookPageId}/sync
             */
            Route::post(
                '/{facebookPageId}/sync',
                [FacebookPageController::class, 'sync']
            )->name('facebook.pages.sync');


            /*
             * Disconnect a Facebook Page from the authenticated
             * user's local account.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * DELETE /api/v1/facebook/pages/{facebookPageId}
             */
            Route::delete(
                '/{facebookPageId}',
                [FacebookPageController::class, 'destroy']
            )->name('facebook.pages.destroy');


            /*
            |--------------------------------------------------------------------------
            | Facebook Posts
            |--------------------------------------------------------------------------
            */

            /*
             * Get saved Facebook Page posts.
             *
             * Posts are read from the local database.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * GET /api/v1/facebook/pages/{facebookPageId}/posts
             */
            Route::get(
                '/{facebookPageId}/posts',
                [FacebookPostController::class, 'index']
            )->name('facebook.pages.posts.index');


            /*
             * Synchronize Facebook Page posts from Meta.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * POST /api/v1/facebook/pages/{facebookPageId}/posts/sync
             */
            Route::post(
                '/{facebookPageId}/posts/sync',
                [FacebookPostController::class, 'sync']
            )->name('facebook.pages.posts.sync');



            /*
            * Get a single saved Facebook Post.
            *
            * {facebookPageId} = External Meta/Facebook Page ID
            * {facebookPostId} = Internal Laravel FacebookPost UUID
            *
            * GET /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}
            */
            Route::get(
                '/{facebookPageId}/posts/{facebookPostId}',
                [FacebookPostController::class, 'show']
            )->whereUuid('facebookPostId')
                ->name('facebook.pages.posts.show');


            /*
            |--------------------------------------------------------------------------
            | Facebook Comments
            |--------------------------------------------------------------------------
            */

            /*
             * Get saved comments for a specific Facebook Post.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * {facebookPostId}
             *     = Internal Laravel FacebookPost UUID
             *
             * GET /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}/comments
             */
            Route::get(
                '/{facebookPageId}/posts/{facebookPostId}/comments',
                [FacebookCommentController::class, 'index']
            )->name('facebook.pages.posts.comments.index');


            /*
             * Synchronize comments for a specific Facebook Post from Meta.
             *
             * {facebookPageId}
             *     = External Meta/Facebook Page ID
             *
             * {facebookPostId}
             *     = Internal Laravel FacebookPost UUID
             *
             * POST /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}/comments/sync
             */
            Route::post(
                '/{facebookPageId}/posts/{facebookPostId}/comments/sync',
                [FacebookCommentController::class, 'sync']
            )->name('facebook.pages.posts.comments.sync');

            /*
            |--------------------------------------------------------------------------
            | Facebook Post Comment Insights
            |--------------------------------------------------------------------------
            |
            | Retrieve the most-liked positive and negative top-level comments
            | for a specific saved Facebook Post.
            |
            | Reads persisted comment sentiment analysis from the local database.
            | Does not call Meta or trigger AI analysis.
            |
            | Optional query parameters:
            |     start_date=2026-10-01
            |     end_date=2026-10-22
            |
            | GET /api/v1/facebook/pages/{facebookPageId}/posts/{facebookPostId}/comments/insights
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/{facebookPageId}/posts/{facebookPostId}/comments/insights',
                [FacebookCommentController::class, 'insights']
            )->whereUuid('facebookPostId')
                ->name('facebook.pages.posts.comments.insights');



            Route::get(
                    '/{facebookPageId}/posts/{facebookPostId}/comments/sentiment-overview',
                    [FacebookCommentController::class, 'sentimentOverview'],
            )->name('facebook.posts.comments.sentiment-overview');


            Route::get(
                '/{facebookPageId}/posts/{facebookPostId}/comments/top-topics',
                [FacebookCommentController::class, 'topTopics'],
            )->name('facebook.posts.comments.top-topics');
        });
});