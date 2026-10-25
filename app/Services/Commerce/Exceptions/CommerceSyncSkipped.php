<?php

namespace App\Services\Commerce\Exceptions;

final class CommerceSyncSkipped extends CommerceSyncException
{
    public function __construct(string $message)
    {
        parent::__construct($message, false);
    }
}
