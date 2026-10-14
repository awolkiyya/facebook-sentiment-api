<?php

declare(strict_types=1);

namespace App\Enums;

enum FacebookSyncTrigger: string
{
    case MANUAL = 'manual';
    case SCHEDULER = 'scheduler';
    case SYSTEM = 'system';
}