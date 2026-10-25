<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Services\Commerce\Data\ProviderSyncResult;

final class PersistCommerceSyncResult
{
    public function execute(CommerceConnection $connection, ProviderSyncResult $result, string $localHash): void
    {
        foreach ($result->entities as $entity) {
            CommerceEntityMapping::query()->updateOrCreate(
                [
                    'commerce_connection_id' => $connection->getKey(),
                    'entity_type' => $entity->entityType,
                    'local_type' => $entity->localType,
                    'local_id' => $entity->localId,
                ],
                [
                    'remote_id' => $entity->remoteId,
                    'remote_parent_id' => $entity->remoteParentId,
                    'local_hash' => $localHash,
                    'last_synced_at' => now('UTC'),
                    'metadata' => $entity->metadata,
                ],
            );
        }
    }
}
