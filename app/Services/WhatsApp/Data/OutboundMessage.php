<?php

namespace App\Services\WhatsApp\Data;

final readonly class OutboundMessage
{
    /** @param list<string> $parameters */
    public function __construct(
        public string $recipient,
        public string $body,
        public ?string $templateName,
        public string $locale,
        public array $parameters = [],
    ) {}
}
