<?php

namespace App\Services\Commerce\Data;

final readonly class ProviderSyncResult
{
    /**
     * @param  list<RemoteEntity>  $entities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $entities = [],
        public array $metadata = [],
    ) {}
}
