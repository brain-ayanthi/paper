<?php

namespace App\Services\WhatsApp\Data;

final readonly class SendResult
{
    public function __construct(
        public bool $successful,
        public ?string $messageId = null,
        public ?string $error = null,
        public bool $retryable = false,
    ) {}
}
