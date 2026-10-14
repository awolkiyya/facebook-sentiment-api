<?php

declare(strict_types=1);

namespace App\Enums;

enum FacebookSyncType: string
{
    case FULL = 'full';
    case POSTS = 'posts';
    case COMMENTS = 'comments';
    case INCREMENTAL = 'incremental';
}