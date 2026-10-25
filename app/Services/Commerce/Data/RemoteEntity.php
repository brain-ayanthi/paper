<?php

namespace App\Services\Commerce\Data;

final readonly class RemoteEntity
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $entityType,
        public string $localType,
        public int $localId,
        public string $remoteId,
        public ?string $remoteParentId = null,
        public array $metadata = [],
    ) {}
}
