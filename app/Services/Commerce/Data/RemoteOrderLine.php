<?php

namespace App\Services\Commerce\Data;

final readonly class RemoteOrderLine
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $remoteId,
        public ?string $remoteProductId,
        public ?string $remoteVariantId,
        public string $name,
        public ?string $sku,
        public string $quantity,
        public string $unitPrice,
        public string $discountAmount,
        public string $taxAmount,
        public string $lineTotal,
        public string $quantityFulfilled = '0',
        public string $quantityRefunded = '0',
        public array $metadata = [],
    ) {}
}
