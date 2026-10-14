<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemLogIndexRequest;
use App\Http\Resources\SystemLogResource;
use App\Models\SystemLog;
use App\Services\ApiResponse;
use App\Services\SystemLogService;
use App\Supports\SystemLogModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SystemLogController extends Controller
{
    /**
     * Display a paginated list of system logs.
     *
     * GET /api/v1/system-logs
     */
    public function index(
        SystemLogIndexRequest $request,
        SystemLogService $systemLogService
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'viewAny',
            SystemLog::class
        );

        $filters = $request->filters();

        /*
        |--------------------------------------------------------------------------
        | Query
        |--------------------------------------------------------------------------
        */

        $query = SystemLog::query()
            ->with([
                'user:id,name,email',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['search'])) {
            $query->search(
                $filters['search']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['action'])) {
            $query->whereAction(
                $filters['action']
            );
        }

        if (!empty($filters['module'])) {
            $query->whereModule(
                $filters['module']
            );
        }

        if (!empty($filters['status'])) {
            $query->whereStatus(
                $filters['status']
            );
        }

        if (!empty($filters['severity'])) {
            $query->whereSeverity(
                $filters['severity']
            );
        }

        if (!empty($filters['user_id'])) {
            $query->whereUser(
                $filters['user_id']
            );
        }

        if (!empty($filters['resource_type'])) {
            $query->whereResourceType(
                $filters['resource_type']
            );
        }

        if (!empty($filters['resource_id'])) {
            $query->whereResourceId(
                $filters['resource_id']
            );
        }

        if (!empty($filters['request_id'])) {
            $query->whereRequestId(
                $filters['request_id']
            );
        }

        if (!empty($filters['ip_address'])) {
            $query->whereIpAddress(
                $filters['ip_address']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Date Range
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['from'])) {
            $query->fromDate(
                $filters['from']
            );
        }

        if (!empty($filters['to'])) {
            $query->toDate(
                $filters['to']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $query->sortBy(
            $filters['sort_by'],
            $filters['sort_direction']
        );

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $logs = $query
            ->paginate(
                perPage: $filters['per_page'],
                page: $filters['page']
            )
            ->appends(
                $request->query()
            );

        /*
        |--------------------------------------------------------------------------
        | Database Audit Log
        |--------------------------------------------------------------------------
        |
        | Records that the authenticated user viewed the
        | system-log collection.
        |
        | Because this is a collection rather than a single
        | SystemLog model, viewedCollection() is used.
        |
        */

        $systemLogService->viewedCollection(
            resourceType: 'SystemLog',
            module: SystemLogModule::SYSTEM,
            description: 'Viewed system logs.',
            metadata: [
                'records_returned' => $logs->count(),
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total_records' => $logs->total(),
                'filters' => $filters,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Laravel Application Log
        |--------------------------------------------------------------------------
        |
        | This is separate from the database audit log.
        |
        | Database audit log:
        |   - business/security audit trail
        |
        | Laravel log:
        |   - operational/debugging trail
        |
        */

        Log::info(
            'System logs viewed.',
            [
                'request_id' => $request->header(
                    'X-Request-ID'
                ),
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email,
                'ip_address' => $request->ip(),
                'records_returned' => $logs->count(),
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total_records' => $logs->total(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        |
        | ApiResponse provides the canonical API response envelope.
        |
        | It automatically handles:
        |
        | - Resource normalization
        | - Pagination metadata
        | - Timestamp
        | - Request ID
        | - API version
        | - X-Request-Id response header
        |
        */

        return ApiResponse::success(
            data: SystemLogResource::collection($logs),
            message: 'System logs retrieved successfully.',
        );
    }

    /**
     * Display a single system log.
     *
     * GET /api/v1/system-logs/{systemLog}
     */
    public function show(
        Request $request,
        SystemLog $systemLog,
        SystemLogService $systemLogService
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        $this->authorize(
            'view',
            $systemLog
        );

        /*
        |--------------------------------------------------------------------------
        | Load User
        |--------------------------------------------------------------------------
        */

        $systemLog->load([
            'user:id,name,email',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Database Audit Log
        |--------------------------------------------------------------------------
        |
        | Here we have the actual SystemLog model.
        |
        | Therefore:
        |
        | resource_type = SystemLog
        | resource_id   = $systemLog->id
        |
        */

        $systemLogService->viewed(
            resource: $systemLog,
            module: SystemLogModule::SYSTEM,
            description: 'Viewed a system log.',
            metadata: [
                'log_action' => $systemLog->action,
                'log_module' => $systemLog->module,
                'log_status' => $systemLog->status,
                'log_severity' => $systemLog->severity,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Laravel Application Log
        |--------------------------------------------------------------------------
        */

        Log::info(
            'System log viewed.',
            [
                'request_id' => $request->header(
                    'X-Request-ID'
                ),
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email,
                'ip_address' => $request->ip(),
                'system_log_id' => $systemLog->id,
                'log_action' => $systemLog->action,
                'log_module' => $systemLog->module,
                'log_status' => $systemLog->status,
                'log_severity' => $systemLog->severity,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            data: new SystemLogResource(
                $systemLog
            ),
            message: 'System log retrieved successfully.',
        );
    }
}
