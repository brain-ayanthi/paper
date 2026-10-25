<?php

namespace App\Services\WhatsApp\Data;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

final readonly class PreparedMessage
{
    /** @param list<string> $parameters */
    public function __construct(
        public Model $reference,
        public Store $store,
        public string $documentType,
        public string $recipient,
        public string $body,
        public string $locale,
        public ?string $templateName,
        public array $parameters,
    ) {}
}
