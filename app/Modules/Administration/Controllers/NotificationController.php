<?php

namespace App\Modules\Administration\Controllers;

use App\Http\Controllers\Controller;


use App\Modules\Administration\Requests\Notification\StoreNotificationRequest;
use App\Modules\Administration\Requests\Notification\UpdateNotificationRequest;
use App\Modules\Administration\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    /**
     * Display a paginated list of notifications.
     */
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $this->authorize('viewAny', Notification::class);

        $perPage = (int) $request->input(
            'per_page',
            10
        );

        $filters = [
            'search' => $request->input('search'),

            'status' => $request->input('status'),

            'type' => $request->input('type'),

            'priority' => $request->input('priority'),

            'audience_type' => $request->input(
                'audience_type'
            ),
        ];

        $notifications = $this->notificationService
            ->paginate(
                $perPage,
                $filters
            );

        return NotificationResource::collection(
            $notifications
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    /**
     * Create a notification as a draft.
     */
    public function store(
        StoreNotificationRequest $request
    ): NotificationResource {
        $this->authorize(
            'create',
            Notification::class
        );

        $notification = $this->notificationService
            ->createDraft(
                $request->validated(),
                $request->user()
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    /**
     * Display a single notification.
     */
    public function show(
        Notification $notification
    ): NotificationResource {
        $this->authorize(
            'view',
            $notification
        );

        $notification = $this->notificationService
            ->find(
                $notification->id,
                true
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update a draft or scheduled notification.
     */
    public function update(
        UpdateNotificationRequest $request,
        Notification $notification
    ): NotificationResource {
        $this->authorize(
            'update',
            $notification
        );

        $notification = $this->notificationService
            ->update(
                $notification,
                $request->validated()
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Publish
    |--------------------------------------------------------------------------
    */

    /**
     * Publish a notification immediately.
     */
    public function publish(
        Notification $notification
    ): NotificationResource {
        $this->authorize(
            'publish',
            $notification
        );

        $notification = $this->notificationService
            ->publish(
                $notification
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    */

    /**
     * Schedule a notification for future publication.
     */
    public function schedule(
        ScheduleNotificationRequest $request,
        Notification $notification
    ): NotificationResource {
        $this->authorize(
            'schedule',
            $notification
        );

        $notification = $this->notificationService
            ->schedule(
                $notification,
                $request->validated('scheduled_at')
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    */

    /**
     * Archive a notification.
     */
    public function archive(
        Notification $notification
    ): NotificationResource {
        $this->authorize(
            'archive',
            $notification
        );

        $notification = $this->notificationService
            ->archive(
                $notification
            );

        return new NotificationResource(
            $notification
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Destroy
    |--------------------------------------------------------------------------
    */

    /**
     * Soft-delete a notification.
     */
    public function destroy(
        Notification $notification
    ): JsonResponse {
        $this->authorize(
            'delete',
            $notification
        );

        $this->notificationService
            ->delete(
                $notification
            );

        return response()->json([
            'message' => 'Notification deleted successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get notification statistics.
     */
    public function statistics(): JsonResponse
    {
        $this->authorize(
            'viewAny',
            Notification::class
        );

        return response()->json([
            'data' => $this->notificationService
                ->statistics(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | My Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Display notifications available to the authenticated user.
     */
    public function mine(
        Request $request
    ): AnonymousResourceCollection {
        $user = $request->user();

        $perPage = (int) $request->input(
            'per_page',
            50
        );

        $notifications = $this->notificationService
            ->paginateForUser(
                $user,
                $perPage
            );

        return NotificationResource::collection(
            $notifications
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Read
    |--------------------------------------------------------------------------
    */

    /**
     * Mark one notification as read for the authenticated user.
     */
    public function read(
        Request $request,
        Notification $notification
    ): JsonResponse {
        $this->notificationService->markAsRead(
            $notification,
            $request->user()
        );

        return response()->json([
            'message' => 'Notification marked as read successfully.',
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function readAll(
        Request $request
    ): JsonResponse {
        $this->notificationService->markAllAsRead(
            $request->user()
        );

        return response()->json([
            'message' => 'All notifications marked as read successfully.',
        ]);
    }
}