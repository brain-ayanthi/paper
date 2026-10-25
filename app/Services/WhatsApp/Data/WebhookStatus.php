<?php

namespace App\Services\WhatsApp\Data;

final readonly class WebhookStatus
{
    public function __construct(
        public string $eventId,
        public string $messageId,
        public string $status,
        public ?string $error = null,
    ) {}
}
