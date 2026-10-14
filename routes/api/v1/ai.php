<?php

use App\Http\Controllers\Api\ModelVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Add this inside your existing v1 route group in routes/api.php
|--------------------------------------------------------------------------
|
| {pipeline} is one of: language | sentiment | priority | topic
| (AiModelPipeline::class — Laravel 404s automatically on any other value)
*/

Route::prefix('ai/model-versions/{pipeline}')->group(function () {
    Route::get('/', [ModelVersionController::class, 'index']);
    Route::post('/', [ModelVersionController::class, 'store']);
    Route::patch('/{modelVersion}', [ModelVersionController::class, 'update']);
    Route::delete('/{modelVersion}', [ModelVersionController::class, 'destroy']);
    Route::post('/{modelVersion}/activate', [ModelVersionController::class, 'activate']);
});