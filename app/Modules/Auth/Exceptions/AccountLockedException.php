<?php

namespace App\Modules\Auth\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

class AccountLockedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $remainingSeconds,
        public readonly ?CarbonInterface $lockedUntil = null,
    ) {
        parent::__construct($message);
    }
}