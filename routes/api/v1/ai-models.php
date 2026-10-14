<?php

use App\Http\Controllers\ModelVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI Model Management
|--------------------------------------------------------------------------
|
| One API resource handles all four AI pipelines:
| - language
| - sentiment
| - priority
| - topic
|
*/


Route::prefix('ai-models')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Model Versions
        |--------------------------------------------------------------------------
        */

        // List model versions for a pipeline
        // GET /api/v1/ai-models/{pipeline}
        Route::get(
            '/{pipeline}',
            [ModelVersionController::class, 'index']
        )->name('ai-models.versions.index');

        // Create a new model version
        // POST /api/v1/ai-models/{pipeline}
        Route::post(
            '/{pipeline}',
            [ModelVersionController::class, 'store']
        )->name('ai-models.versions.store');

        // Update a model version
        // PUT/PATCH /api/v1/ai-models/{pipeline}/{modelVersion}
        Route::match(
            ['put', 'patch'],
            '/{pipeline}/{modelVersion}',
            [ModelVersionController::class, 'update']
        )->name('ai-models.versions.update');

        // Activate a model version
        // POST /api/v1/ai-models/{pipeline}/{modelVersion}/activate
        Route::post(
            '/{pipeline}/{modelVersion}/activate',
            [ModelVersionController::class, 'activate']
        )->name('ai-models.versions.activate');

        // Delete a model version
        // DELETE /api/v1/ai-models/{pipeline}/{modelVersion}
        Route::delete(
            '/{pipeline}/{modelVersion}',
            [ModelVersionController::class, 'destroy']
        )->name('ai-models.versions.destroy');
});
