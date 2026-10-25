<?php

namespace App\Services\Commerce\Exceptions;

use RuntimeException;

class CommerceSyncException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }
}
