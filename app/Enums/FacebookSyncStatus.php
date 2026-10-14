<?php

declare(strict_types=1);

namespace App\Enums;

enum FacebookSyncStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED => true,

            self::QUEUED,
            self::RUNNING => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::QUEUED,
            self::RUNNING => true,

            self::COMPLETED,
            self::FAILED,
            self::CANCELLED => false,
        };
    }

    public function canRetry(): bool
    {
        return match ($this) {
            self::FAILED,
            self::CANCELLED => true,

            self::QUEUED,
            self::RUNNING,
            self::COMPLETED => false,
        };
    }

    public function canCancel(): bool
    {
        return match ($this) {
            self::QUEUED,
            self::RUNNING => true,

            self::COMPLETED,
            self::FAILED,
            self::CANCELLED => false,
        };
    }
}