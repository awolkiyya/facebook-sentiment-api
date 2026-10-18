
<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Insights\Controllers\FacebookPostInsightController;

Route::prefix('ai-insights')
    ->name('ai-insights.')
    ->group(function () {
        // Generate or regenerate an insight for one Facebook post.
        Route::post(
            '/facebook/posts/{postId}/insights',
            [FacebookPostInsightController::class, 'generate']
        )->name('facebook.posts.insights.generate');

        // Get the latest saved insight for one Facebook post.
        Route::get(
            '/facebook/posts/{postId}/insights/latest',
            [FacebookPostInsightController::class, 'latest']
        )->name('facebook.posts.insights.latest');

        // Get all saved post insights, with pagination and filters.
        Route::get(
            '/facebook/posts/insights',
            [FacebookPostInsightController::class, 'index']
        )->name('facebook.posts.insights.index');
    });
