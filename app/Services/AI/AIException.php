<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use Throwable;

class AIException extends RuntimeException
{
    public function __construct(
        string $message = 'AI service error.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous,
        );
    }
}