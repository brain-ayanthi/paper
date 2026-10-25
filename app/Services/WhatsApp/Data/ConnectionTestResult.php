<?php

namespace App\Services\WhatsApp\Data;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $successful,
        public string $message,
        public array $metadata = [],
    ) {}
}
