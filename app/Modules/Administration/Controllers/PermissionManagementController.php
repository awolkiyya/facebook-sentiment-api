<?php

namespace App\Modules\Administration\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Modules\Administration\Resources\PermissionResource;
use App\Modules\Administration\Services\PermissionService;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class PermissionManagementController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService
    ) {
    }

    /**
     * Display all available API permissions.
     */
    public function index(): JsonResponse
    {
        Log::info(
            'PermissionManagementController::index() started.',
            [
                'user_id' => auth()->id(),
                'guard' => 'api',
                'route' => request()->route()?->uri(),
                'method' => request()->method(),
            ]
        );

        try {
            Log::info(
                'Authorizing permissions.view.',
                [
                    'user_id' => auth()->id(),
                    'permission' => 'permissions.view',
                ]
            );

            Gate::authorize(
                'viewAny',
                Permission::class
            );

            Log::info(
                'Permission authorization successful.',
                [
                    'user_id' => auth()->id(),
                    'permission' => 'permissions.view',
                ]
            );

            Log::info(
                'Calling PermissionService::list().',
                [
                    'user_id' => auth()->id(),
                ]
            );

            $permissions = $this->permissionService->list();

            Log::info(
                'PermissionService::list() returned successfully.',
                [
                    'user_id' => auth()->id(),
                    'permission_count' => $permissions->count(),
                ]
            );

            Log::info(
                'Creating PermissionResource collection.',
                [
                    'user_id' => auth()->id(),
                    'permission_count' => $permissions->count(),
                ]
            );

            $resource = PermissionResource::collection(
                $permissions
            );

            Log::info(
                'PermissionResource collection created successfully.',
                [
                    'user_id' => auth()->id(),
                ]
            );

            Log::info(
                'Creating successful API response.',
                [
                    'user_id' => auth()->id(),
                    'message' => 'Permissions retrieved successfully.',
                ]
            );

            $response = ApiResponse::success(
                data: $resource,
                message: 'Permissions retrieved successfully.'
            );

            Log::info(
                'PermissionManagementController::index() completed.',
                [
                    'user_id' => auth()->id(),
                    'status' => $response->getStatusCode(),
                ]
            );

            return $response;
        } catch (Throwable $exception) {
            Log::error(
                'PermissionManagementController::index() failed.',
                [
                    'user_id' => auth()->id(),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }
    }
}
