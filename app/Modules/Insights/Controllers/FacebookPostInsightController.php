<?php

namespace App\Modules\Insights\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Insights\Resources\PostAiInsightResource;
use App\Modules\Insights\Requests\GeneratePostInsightRequest;
use App\Modules\Insights\Services\FacebookPostInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class FacebookPostInsightController extends Controller
{
    public function __construct(
        private readonly FacebookPostInsightService $insightService,
    ) {}

    /**
     * Queue AI insight generation for a Facebook post.
     *
     * An omitted date_range requests an all-time insight.
     * A supplied date_range requests an insight for that exact period.
     */
    public function generate(
        GeneratePostInsightRequest $request,
        string $postId,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $forceRegeneration = (bool) (
                $validated['regenerate'] ?? false
            );

            $dateFrom = $validated['date_range']['from'] ?? null;
            $dateTo = $validated['date_range']['to'] ?? null;

            $insight = $this->insightService->queueGeneration(
                postId: $postId,
                language: $validated['language'],
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                requestedModel: $validated['model'] ?? null,
                forceRegeneration: $forceRegeneration,
            );

            $message = match ($insight->status) {
                'PROCESSING' =>
                    'Post insight generation is already in progress.',

                'COMPLETED' =>
                    'Post insight generation is already completed.',

                'FAILED' =>
                    'The previous generation failed. A new generation may be requested.',

                default =>
                    'Post insight generation has been queued.',
            };

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => PostAiInsightResource::make($insight)
                    ->resolve($request),
            ], 202);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'The insight request could not be processed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error(
                'Failed to queue Facebook post insight generation.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'post_id' => $postId,
                    'language' => $validated['language'] ?? null,
                    'date_from' => $validated['date_range']['from'] ?? null,
                    'date_to' => $validated['date_range']['to'] ?? null,
                    'model' => $validated['model'] ?? null,
                    'regenerate' => $validated['regenerate'] ?? false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Unable to queue post insight generation.',
            ], 500);
        }
    }

    /**
     * Retrieve the latest completed insight for a Facebook post.
     *
     * Optionally filter by language and date range.
     */
    public function latest(
        Request $request,
        string $postId,
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'language' => [
                    'sometimes',
                    'string',
                    'in:en,am,or',
                ],
                'date_from' => [
                    'sometimes',
                    'nullable',
                    'date_format:Y-m-d',
                ],
                'date_to' => [
                    'sometimes',
                    'nullable',
                    'date_format:Y-m-d',
                    'after_or_equal:date_from',
                ],
            ]);

            $dateFrom = $validated['date_from'] ?? null;
            $dateTo = $validated['date_to'] ?? null;

            if (($dateFrom === null) !== ($dateTo === null)) {
                throw ValidationException::withMessages([
                    'date_range' => [
                        'Both date_from and date_to must be provided together.',
                    ],
                ]);
            }

            $insight = $this->insightService->getLatestInsight(
                postId: $postId,
                language: $validated['language'] ?? null,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );

            if (! $insight) {
                return response()->json([
                    'success' => true,
                    'message' => 'No completed insight was found for this post and date range.',
                    'data' => null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Post insight retrieved successfully.',
                'data' => PostAiInsightResource::make($insight)
                    ->resolve($request),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid insight filters.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error(
                'Failed to retrieve Facebook post insight.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'post_id' => $postId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve the post insight.',
            ], 500);
        }
    }

    /**
     * List saved insights for the AI Insights dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'language' => [
                    'sometimes',
                    'string',
                    'in:en,am,or',
                ],
                'model' => [
                    'sometimes',
                    'string',
                    'max:100',
                ],
                'post_id' => [
                    'sometimes',
                    'string',
                    'max:100',
                ],
                'date_from' => [
                    'sometimes',
                    'nullable',
                    'date_format:Y-m-d',
                ],
                'date_to' => [
                    'sometimes',
                    'nullable',
                    'date_format:Y-m-d',
                    'after_or_equal:date_from',
                ],
                'from' => [
                    'sometimes',
                    'nullable',
                    'date',
                ],
                'to' => [
                    'sometimes',
                    'nullable',
                    'date',
                    'after_or_equal:from',
                ],
                'per_page' => [
                    'sometimes',
                    'integer',
                    'min:1',
                    'max:100',
                ],
            ]);

            $dateFrom = $validated['date_from'] ?? null;
            $dateTo = $validated['date_to'] ?? null;

            if (($dateFrom === null) !== ($dateTo === null)) {
                throw ValidationException::withMessages([
                    'date_range' => [
                        'Both date_from and date_to must be provided together.',
                    ],
                ]);
            }

            $insights = $this->insightService->paginateInsights(
                filters: $validated,
                perPage: (int) ($validated['per_page'] ?? 15),
            );

            return response()->json([
                'success' => true,
                'message' => 'Post insights retrieved successfully.',
                'data' => PostAiInsightResource::collection(
                    $insights->getCollection()
                )->resolve($request),
                'meta' => [
                    'current_page' => $insights->currentPage(),
                    'last_page' => $insights->lastPage(),
                    'per_page' => $insights->perPage(),
                    'total' => $insights->total(),
                    'from' => $insights->firstItem(),
                    'to' => $insights->lastItem(),
                ],
                'links' => [
                    'first' => $insights->url(1),
                    'last' => $insights->url($insights->lastPage()),
                    'prev' => $insights->previousPageUrl(),
                    'next' => $insights->nextPageUrl(),
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid insight filters.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error(
                'Failed to list Facebook post insights.',
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve post insights.',
            ], 500);
        }
    }
}
