<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class NotificationService
{
    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_MAINTENANCE = 'maintenance';
    public const TYPE_SECURITY = 'security';
    public const TYPE_POLICY = 'policy';
    public const TYPE_SYSTEM = 'system';

    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_IMPORTANT = 'important';
    public const PRIORITY_URGENT = 'urgent';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const AUDIENCE_ALL_USERS = 'all_users';
    public const AUDIENCE_ROLE = 'role';
    public const AUDIENCE_SELECTED_USERS = 'selected_users';

    /*
    |--------------------------------------------------------------------------
    | List / Pagination
    |--------------------------------------------------------------------------
    */

    /**
     * Get paginated notifications.
     */
    public function paginate(
        int $perPage = 10,
        array $filters = []
    ): LengthAwarePaginator {
        $query = Notification::query()
            ->with([
                'creator:id,name',
            ])
            ->withCount('recipients')
            ->latest('created_at');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($query) use ($search) {
                $query
                    ->where(
                        'title',
                        'ILIKE',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'message',
                        'ILIKE',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Type
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['type'])) {
            $query->where(
                'type',
                $filters['type']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Priority
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['priority'])) {
            $query->where(
                'priority',
                $filters['priority']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Audience
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['audience_type'])) {
            $query->where(
                'audience_type',
                $filters['audience_type']
            );
        }

        return $query->paginate(
            max(
                1,
                min($perPage, 100)
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | My Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Get paginated notifications for the authenticated user.
     *
     * Only notifications that have been materialized into
     * notification_recipients for this user are returned.
     */
    public function paginateForUser(
        User $user,
        int $perPage = 50
    ): LengthAwarePaginator {
        return Notification::query()
            ->whereHas(
                'recipients',
                function ($query) use ($user) {
                    $query->where(
                        'user_id',
                        $user->id
                    );
                }
            )
            ->with([
                'creator:id,name',
            ])
            ->latest('created_at')
            ->paginate(
                max(
                    1,
                    min($perPage, 100)
                )
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Read
    |--------------------------------------------------------------------------
    */

    /**
     * Mark one notification as read for a user.
     */
    public function markAsRead(
        Notification $notification,
        User $user
    ): void {
        NotificationRecipient::query()
            ->where(
                'notification_id',
                $notification->id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(
        User $user
    ): int {
        return NotificationRecipient::query()
            ->where(
                'user_id',
                $user->id
            )
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Find
    |--------------------------------------------------------------------------
    */

    /**
     * Get a single notification.
     */
    public function find(
        string $id,
        bool $withRecipients = false
    ): Notification {
        $query = Notification::query()
            ->with([
                'creator:id,name',
            ]);

        if ($withRecipients) {
            $query->with([
                'recipients.user:id,name,email',
            ]);
        }

        return $query->findOrFail($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /**
     * Create a notification as a draft.
     */
    public function createDraft(
        array $data,
        User $creator
    ): Notification {
        $audienceType = $data['audience_type']
            ?? self::AUDIENCE_ALL_USERS;

        $audienceData = $this->normalizeAudienceData(
            $audienceType,
            $data['audience_data'] ?? []
        );

        return DB::transaction(
            function () use (
                $data,
                $creator,
                $audienceType,
                $audienceData
            ) {
                return Notification::create([
                    'title' => $data['title'],

                    'message' => $data['message'],

                    'type' => $data['type']
                        ?? self::TYPE_ANNOUNCEMENT,

                    'priority' => $data['priority']
                        ?? self::PRIORITY_NORMAL,

                    'status' => self::STATUS_DRAFT,

                    'audience_type' => $audienceType,

                    'audience_data' => $audienceData,

                    'published_at' => null,

                    'scheduled_at' => null,

                    'expires_at' => $data['expires_at']
                        ?? null,

                    'created_by' => $creator->id,
                ]);
            }
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
        Notification $notification,
        array $data
    ): Notification {
        $this->ensureEditable(
            $notification
        );

        $audienceType = $data['audience_type']
            ?? $notification->audience_type;

        /*
        |--------------------------------------------------------------------------
        | Audience Data
        |--------------------------------------------------------------------------
        */

        $audienceData = array_key_exists(
            'audience_data',
            $data
        )
            ? $this->normalizeAudienceData(
                $audienceType,
                $data['audience_data']
            )
            : $notification->audience_data;

        return DB::transaction(
            function () use (
                $notification,
                $data,
                $audienceType,
                $audienceData
            ) {
                $notification->update([
                    'title' => $data['title']
                        ?? $notification->title,

                    'message' => $data['message']
                        ?? $notification->message,

                    'type' => $data['type']
                        ?? $notification->type,

                    'priority' => $data['priority']
                        ?? $notification->priority,

                    'audience_type' => $audienceType,

                    'audience_data' => $audienceData,

                    'expires_at' => array_key_exists(
                        'expires_at',
                        $data
                    )
                        ? $data['expires_at']
                        : $notification->expires_at,

                    'scheduled_at' => array_key_exists(
                        'scheduled_at',
                        $data
                    )
                        ? $data['scheduled_at']
                        : $notification->scheduled_at,
                ]);

                return $notification->fresh([
                    'creator:id,name',
                ]);
            }
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
    ): Notification {
        if (!$notification->isPublishable()) {
            throw ValidationException::withMessages([
                'status' => [
                    'This notification cannot be published.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Expiration Check
        |--------------------------------------------------------------------------
        */

        if ($notification->isExpired()) {
            throw ValidationException::withMessages([
                'expires_at' => [
                    'This notification has already expired.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Audience Validation
        |--------------------------------------------------------------------------
        */

        $this->validateAudience(
            $notification
        );

        return DB::transaction(
            function () use (
                $notification
            ) {
                $notification->update([
                    'status' => self::STATUS_PUBLISHED,

                    'published_at' => now(),

                    'scheduled_at' => null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Materialize Recipients
                |--------------------------------------------------------------------------
                |
                | The audience configuration is stored on notifications.
                | At publication time, the actual active users are resolved
                | into notification_recipients.
                |
                */

                $this->createRecipients(
                    $notification
                );

                return $notification->fresh([
                    'creator:id,name',
                ]);
            }
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
        Notification $notification,
        string $scheduledAt
    ): Notification {
        $this->ensureEditable(
            $notification
        );

        /*
        |--------------------------------------------------------------------------
        | Parse Scheduled Time
        |--------------------------------------------------------------------------
        */

        try {
            $scheduledDate = now()->parse(
                $scheduledAt
            );
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'scheduled_at' => [
                    'The scheduled time is invalid.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Must Be Future
        |--------------------------------------------------------------------------
        */

        if ($scheduledDate->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => [
                    'The scheduled time must be in the future.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Must Be Before Expiration
        |--------------------------------------------------------------------------
        */

        if (
            $notification->expires_at !== null
            && $scheduledDate->greaterThanOrEqualTo(
                $notification->expires_at
            )
        ) {
            throw ValidationException::withMessages([
                'scheduled_at' => [
                    'The scheduled time must be before the expiration time.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Audience Validation
        |--------------------------------------------------------------------------
        */

        $this->validateAudience(
            $notification
        );

        return DB::transaction(
            function () use (
                $notification,
                $scheduledDate
            ) {
                $notification->update([
                    'status' => self::STATUS_SCHEDULED,

                    'scheduled_at' => $scheduledDate,

                    'published_at' => null,
                ]);

                return $notification->fresh([
                    'creator:id,name',
                ]);
            }
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
    ): Notification {
        if ($notification->isArchived()) {
            return $notification;
        }

        return DB::transaction(
            function () use (
                $notification
            ) {
                $notification->update([
                    'status' => self::STATUS_ARCHIVED,
                ]);

                return $notification->fresh([
                    'creator:id,name',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    /**
     * Restore a soft-deleted notification.
     */
    public function restore(
        Notification $notification
    ): Notification {
        if (!$notification->trashed()) {
            throw ValidationException::withMessages([
                'notification' => [
                    'The notification is not deleted.',
                ],
            ]);
        }

        $notification->restore();

        return $notification->fresh([
            'creator:id,name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    /**
     * Soft-delete a notification.
     */
    public function delete(
        Notification $notification
    ): void {
        DB::transaction(
            function () use (
                $notification
            ) {
                $notification->delete();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get notification statistics.
     */
    public function statistics(): array
    {
        return [
            'total' => Notification::query()
                ->count(),

            'published' => Notification::query()
                ->where(
                    'status',
                    self::STATUS_PUBLISHED
                )
                ->count(),

            'scheduled' => Notification::query()
                ->where(
                    'status',
                    self::STATUS_SCHEDULED
                )
                ->count(),

            'drafts' => Notification::query()
                ->where(
                    'status',
                    self::STATUS_DRAFT
                )
                ->count(),

            'archived' => Notification::query()
                ->where(
                    'status',
                    self::STATUS_ARCHIVED
                )
                ->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Publish notifications whose scheduled time has arrived.
     *
     * Intended to be called by a scheduled command/job.
     */
    public function publishDueNotifications(): int
    {
        $notifications = Notification::query()
            ->where(
                'status',
                self::STATUS_SCHEDULED
            )
            ->whereNotNull(
                'scheduled_at'
            )
            ->where(
                'scheduled_at',
                '<=',
                now()
            )
            ->where(function ($query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere(
                        'expires_at',
                        '>',
                        now()
                    );
            })
            ->get();

        $published = 0;

        foreach ($notifications as $notification) {
            try {
                $this->publish(
                    $notification
                );

                $published++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $published;
    }

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    */

    /**
     * Archive published notifications that have expired.
     */
    public function archiveExpiredNotifications(): int
    {
        return Notification::query()
            ->where(
                'status',
                self::STATUS_PUBLISHED
            )
            ->whereNotNull(
                'expires_at'
            )
            ->where(
                'expires_at',
                '<=',
                now()
            )
            ->update([
                'status' => self::STATUS_ARCHIVED,
                'updated_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Recipient Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Create notification recipients from the configured audience.
     *
     * Recipients are materialized only when the notification is published.
     */
    protected function createRecipients(
        Notification $notification
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        |
        | Publishing the same notification more than once must never create
        | duplicate recipient rows.
        |
        */

        if (
            $notification
                ->recipients()
                ->exists()
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Base User Query
        |--------------------------------------------------------------------------
        |
        | Only active users receive notifications.
        |
        */

        $userQuery = User::query()
            ->where(
                'is_active',
                true
            );

        /*
        |--------------------------------------------------------------------------
        | Resolve Audience
        |--------------------------------------------------------------------------
        */

        switch (
            $notification->audience_type
        ) {
            /*
            |--------------------------------------------------------------------------
            | All Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ALL_USERS:

                /*
                | No additional filtering.
                */

                break;

            /*
            |--------------------------------------------------------------------------
            | Role-Based Audience
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ROLE:

                $roles = $notification
                    ->audience_data['roles']
                    ?? [];

                if (empty($roles)) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'At least one role must be selected.',
                        ],
                    ]);
                }

                $userQuery->whereHas(
                    'roles',
                    function ($query) use (
                        $roles
                    ) {
                        $query->whereIn(
                            'name',
                            $roles
                        );
                    }
                );

                break;

            /*
            |--------------------------------------------------------------------------
            | Selected Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_SELECTED_USERS:

                $userIds = $notification
                    ->audience_data['user_ids']
                    ?? [];

                if (empty($userIds)) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'At least one user must be selected.',
                        ],
                    ]);
                }

                $userQuery->whereIn(
                    'id',
                    $userIds
                );

                break;

            /*
            |--------------------------------------------------------------------------
            | Invalid Audience
            |--------------------------------------------------------------------------
            */

            default:

                throw ValidationException::withMessages([
                    'audience_type' => [
                        'Invalid notification audience type.',
                    ],
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Materialize Recipients
        |--------------------------------------------------------------------------
        |
        | Chunking prevents memory problems when the system has many users.
        |
        */

        $userQuery
            ->select('id')
            ->chunkById(
                500,
                function ($users) use (
                    $notification
                ) {
                    $now = now();

                    $records = [];

                    foreach ($users as $user) {
                        $records[] = [
                            'id' => (string) Str::uuid(),

                            'notification_id' =>
                                $notification->id,

                            'user_id' =>
                                $user->id,

                            'read_at' => null,

                            'created_at' => $now,

                            'updated_at' => $now,
                        ];
                    }

                    if (
                        !empty($records)
                    ) {
                        NotificationRecipient::query()
                            ->insert(
                                $records
                            );
                    }
                }
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Audience Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate notification audience configuration.
     */
    protected function validateAudience(
        Notification $notification
    ): void {
        switch (
            $notification->audience_type
        ) {
            /*
            |--------------------------------------------------------------------------
            | All Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ALL_USERS:

                return;

            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ROLE:

                $roles = $notification
                    ->audience_data['roles']
                    ?? [];

                if (empty($roles)) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'At least one role must be selected.',
                        ],
                    ]);
                }

                $roles = array_values(
                    array_unique(
                        $roles
                    )
                );

                $existingRoles = Role::query()
                    ->where(
                        'guard_name',
                        'api'
                    )
                    ->whereIn(
                        'name',
                        $roles
                    )
                    ->pluck('name')
                    ->all();

                $missingRoles = array_values(
                    array_diff(
                        $roles,
                        $existingRoles
                    )
                );

                if (
                    !empty($missingRoles)
                ) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'One or more selected roles do not exist.',
                        ],
                    ]);
                }

                return;

            /*
            |--------------------------------------------------------------------------
            | Selected Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_SELECTED_USERS:

                $userIds = $notification
                    ->audience_data['user_ids']
                    ?? [];

                if (empty($userIds)) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'At least one user must be selected.',
                        ],
                    ]);
                }

                $uniqueUserIds = array_values(
                    array_unique(
                        $userIds
                    )
                );

                /*
                |--------------------------------------------------------------------------
                | Validate Active Users
                |--------------------------------------------------------------------------
                */

                $activeUserCount = User::query()
                    ->where(
                        'is_active',
                        true
                    )
                    ->whereIn(
                        'id',
                        $uniqueUserIds
                    )
                    ->count();

                if (
                    $activeUserCount !== count(
                        $uniqueUserIds
                    )
                ) {
                    throw ValidationException::withMessages([
                        'audience_data' => [
                            'One or more selected users are invalid or inactive.',
                        ],
                    ]);
                }

                return;

            /*
            |--------------------------------------------------------------------------
            | Invalid Audience
            |--------------------------------------------------------------------------
            */

            default:

                throw ValidationException::withMessages([
                    'audience_type' => [
                        'Invalid notification audience type.',
                    ],
                ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Audience Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize audience data before storing it.
     */
    protected function normalizeAudienceData(
        string $audienceType,
        array $audienceData
    ): ?array {
        switch (
            $audienceType
        ) {
            /*
            |--------------------------------------------------------------------------
            | All Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ALL_USERS:

                return null;

            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_ROLE:

                $roles = $audienceData['roles']
                    ?? [];

                $roles = array_values(
                    array_unique(
                        array_filter(
                            $roles,
                            fn ($role) =>
                                is_string($role)
                                && trim($role) !== ''
                        )
                    )
                );

                return [
                    'roles' => $roles,
                ];

            /*
            |--------------------------------------------------------------------------
            | Selected Users
            |--------------------------------------------------------------------------
            */

            case self::AUDIENCE_SELECTED_USERS:

                $userIds = $audienceData['user_ids']
                    ?? [];

                $userIds = array_values(
                    array_unique(
                        array_filter(
                            $userIds,
                            fn ($id) =>
                                is_string($id)
                                && trim($id) !== ''
                        )
                    )
                );

                return [
                    'user_ids' => $userIds,
                ];

            /*
            |--------------------------------------------------------------------------
            | Invalid Audience
            |--------------------------------------------------------------------------
            */

            default:

                throw ValidationException::withMessages([
                    'audience_type' => [
                        'Invalid notification audience type.',
                    ],
                ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Editability Guard
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the notification can still be edited.
     *
     * Draft and scheduled notifications remain editable.
     * Published and archived notifications are immutable.
     */
    protected function ensureEditable(
        Notification $notification
    ): void {
        if (
            $notification->isPublished()
            || $notification->isArchived()
        ) {
            throw ValidationException::withMessages([
                'notification' => [
                    'Published or archived notifications cannot be edited.',
                ],
            ]);
        }
    }
}