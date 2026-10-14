<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiModelPipeline;
use App\Http\Requests\StoreModelVersionRequest;
use App\Http\Requests\UpdateModelVersionRequest;
use App\Http\Resources\ModelVersionResource;
use App\Services\SystemLogService;
use App\Supports\SystemLogModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller for managing AI model versions across all pipelines:
 *
 * - language
 * - sentiment
 * - priority
 * - topic
 */
class ModelVersionController extends Controller
{
    /**
     * Create the controller.
     */
    public function __construct(
        private readonly SystemLogService $systemLogService,
    ) {
    }

    /**
     * List model versions for a pipeline.
     *
     * Permission:
     *     ai_models.view
     */
    public function index(
        AiModelPipeline $pipeline,
        Request $request,
    ): JsonResponse {
        $startedAt = $this->systemLogService->startTimer();

        $this->authorize(
            'viewAny',
            $pipeline->model()
        );

        $perPage = min(
            max(
                $request->integer('per_page', 20),
                1
            ),
            100
        );

        $versions = $pipeline
            ->newQuery()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $this->systemLogService->viewedCollection(
            resourceType: class_basename($pipeline->model()),
            module: SystemLogModule::AI,
            description: "{$pipeline->label()} model versions viewed",
            metadata: [
                'pipeline' => $pipeline->value,
                'per_page' => $perPage,
                'page' => $versions->currentPage(),
                'total' => $versions->total(),
            ],
            durationMs: $this->systemLogService->elapsedMilliseconds(
                $startedAt
            ),
        );

        return ModelVersionResource::collection($versions)
            ->additional([
                'pipeline' => $pipeline->value,
            ])
            ->response();
    }

    /**
     * Create a new model version.
     *
     * Permission:
     *     ai_models.create
     */
    public function store(
        AiModelPipeline $pipeline,
        StoreModelVersionRequest $request,
    ): JsonResponse {
        $startedAt = $this->systemLogService->startTimer();

        $this->authorize(
            'create',
            $pipeline->model()
        );

        $version = $pipeline
            ->newQuery()
            ->create(
                $request->validated()
            );

        $this->systemLogService->created(
            resource: $version,
            module: SystemLogModule::AI,
            description:
                "{$pipeline->label()} AI model version created",
            newValues: [
                'pipeline' => $pipeline->value,
                'name' => $version->name,
                'version' => $version->version,
                'model_name' => $version->model_name,
                'framework' => $version->framework,
                'accuracy' => $version->accuracy,
                'macro_f1' => $version->macro_f1,
                'supported_languages' =>
                    $version->supported_languages,
                'is_active' => $version->is_active,
            ],
            metadata: [
                'pipeline' => $pipeline->value,
            ],
            durationMs: $this->systemLogService->elapsedMilliseconds(
                $startedAt
            ),
        );

        return (new ModelVersionResource($version))
            ->additional([
                'pipeline' => $pipeline->value,
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing model version.
     *
     * Permission:
     *     ai_models.update
     */
    public function update(
        AiModelPipeline $pipeline,
        string $modelVersion,
        UpdateModelVersionRequest $request,
    ): JsonResponse {
        $startedAt = $this->systemLogService->startTimer();

        /*
        |--------------------------------------------------------------------------
        | Resolve the model inside the requested pipeline.
        |--------------------------------------------------------------------------
        */

        $version = $pipeline
            ->newQuery()
            ->findOrFail($modelVersion);

        $this->authorize(
            'update',
            $version
        );

        /*
        |--------------------------------------------------------------------------
        | Capture old values.
        |--------------------------------------------------------------------------
        */

        $oldValues = [
            'name' => $version->name,
            'version' => $version->version,
            'model_name' => $version->model_name,
            'framework' => $version->framework,
            'accuracy' => $version->accuracy,
            'macro_f1' => $version->macro_f1,
            'supported_languages' =>
                $version->supported_languages,
            'is_active' => $version->is_active,
        ];

        /*
        |--------------------------------------------------------------------------
        | Update.
        |--------------------------------------------------------------------------
        */

        $version->update(
            $request->validated()
        );

        $version->refresh();

        /*
        |--------------------------------------------------------------------------
        | Capture new values.
        |--------------------------------------------------------------------------
        */

        $newValues = [
            'name' => $version->name,
            'version' => $version->version,
            'model_name' => $version->model_name,
            'framework' => $version->framework,
            'accuracy' => $version->accuracy,
            'macro_f1' => $version->macro_f1,
            'supported_languages' =>
                $version->supported_languages,
            'is_active' => $version->is_active,
        ];

        /*
        |--------------------------------------------------------------------------
        | Audit update.
        |--------------------------------------------------------------------------
        */

        $this->systemLogService->updated(
            resource: $version,
            module: SystemLogModule::AI,
            oldValues: $oldValues,
            newValues: $newValues,
            description:
                "{$pipeline->label()} AI model version updated",
            metadata: [
                'pipeline' => $pipeline->value,
            ],
            durationMs: $this->systemLogService->elapsedMilliseconds(
                $startedAt
            ),
        );

        return (new ModelVersionResource($version))
            ->additional([
                'pipeline' => $pipeline->value,
            ])
            ->response();
    }

    /**
     * Activate a model version for production.
     *
     * Only one version with the same model `name` can be active.
     *
     * Permission:
     *     ai_models.activate
     */
    public function activate(
        AiModelPipeline $pipeline,
        string $modelVersion,
    ): JsonResponse {
        $startedAt = $this->systemLogService->startTimer();

        /*
        |--------------------------------------------------------------------------
        | Resolve model inside the requested pipeline.
        |--------------------------------------------------------------------------
        */

        $version = $pipeline
            ->newQuery()
            ->findOrFail($modelVersion);

        $this->authorize(
            'activate',
            $version
        );

        /*
        |--------------------------------------------------------------------------
        | Find the currently active version.
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do NOT use:
        |
        |     whereKey('!=', $version->getKey())
        |
        | whereKey() does not accept an operator.
        |
        | The correct form is:
        |
        |     where($version->getKeyName(), '!=', $version->getKey())
        |
        | We also filter by `name` because BaseModelVersion::activate()
        | deactivates only versions belonging to the same model family.
        |--------------------------------------------------------------------------
        */

        $previousActiveVersion = $pipeline
            ->newQuery()
            ->where('name', $version->name)
            ->where('is_active', true)
            ->where(
                $version->getKeyName(),
                '!=',
                $version->getKey()
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Activate atomically.
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            fn () => $version->activate()
        );

        $version->refresh();

        /*
        |--------------------------------------------------------------------------
        | Audit activation.
        |--------------------------------------------------------------------------
        */

        $this->systemLogService->statusChanged(
            resource: $version,
            module: SystemLogModule::AI,
            oldStatus: $previousActiveVersion?->version,
            newStatus: $version->version,
            description:
                "{$pipeline->label()} AI model version activated",
            metadata: [
                'pipeline' => $pipeline->value,

                'previous_active_model_id' =>
                    $previousActiveVersion?->getKey(),

                'previous_active_version' =>
                    $previousActiveVersion?->version,

                'activated_model_id' =>
                    $version->getKey(),

                'activated_version' =>
                    $version->version,

                'model_name' =>
                    $version->model_name,

                'model_family' =>
                    $version->name,
            ],
            durationMs: $this->systemLogService->elapsedMilliseconds(
                $startedAt
            ),
        );

        return (new ModelVersionResource($version))
            ->additional([
                'pipeline' => $pipeline->value,
            ])
            ->response();
    }

    /**
     * Delete a model version.
     *
     * Active model versions cannot be deleted.
     *
     * Permission:
     *     ai_models.delete
     */
    public function destroy(
        AiModelPipeline $pipeline,
        string $modelVersion,
    ): JsonResponse {
        $startedAt = $this->systemLogService->startTimer();

        /*
        |--------------------------------------------------------------------------
        | Resolve model inside the requested pipeline.
        |--------------------------------------------------------------------------
        */

        $version = $pipeline
            ->newQuery()
            ->findOrFail($modelVersion);

        $this->authorize(
            'delete',
            $version
        );

        /*
        |--------------------------------------------------------------------------
        | Protect active production model.
        |--------------------------------------------------------------------------
        */

        if ($version->is_active) {
            return response()->json([
                'message' =>
                    'An active model version cannot be deleted. '
                    . 'Activate a different version first.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Capture values before deletion.
        |--------------------------------------------------------------------------
        */

        $oldValues = [
            'pipeline' => $pipeline->value,
            'name' => $version->name,
            'version' => $version->version,
            'model_name' => $version->model_name,
            'framework' => $version->framework,
            'accuracy' => $version->accuracy,
            'macro_f1' => $version->macro_f1,
            'supported_languages' =>
                $version->supported_languages,
            'is_active' => $version->is_active,
        ];

        /*
        |--------------------------------------------------------------------------
        | Delete.
        |--------------------------------------------------------------------------
        */

        $version->delete();

        /*
        |--------------------------------------------------------------------------
        | Audit deletion.
        |--------------------------------------------------------------------------
        */

        $this->systemLogService->deleted(
            resource: $version,
            module: SystemLogModule::AI,
            description:
                "{$pipeline->label()} AI model version deleted",
            oldValues: $oldValues,
            metadata: [
                'pipeline' => $pipeline->value,
                'model_version_id' => $modelVersion,
            ],
            durationMs: $this->systemLogService->elapsedMilliseconds(
                $startedAt
            ),
        );

        return response()->noContent();
    }
}
