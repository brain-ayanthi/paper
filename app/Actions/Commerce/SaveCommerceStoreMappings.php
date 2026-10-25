<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveCommerceStoreMappings
{
    /** @param list<array<string, mixed>> $mappings */
    public function execute(CommerceConnection $connection, array $mappings): void
    {
        $activeRemoteIds = collect($mappings)
            ->filter(fn (array $mapping) => ($mapping['is_active'] ?? false)
                && ($mapping['inventory_mode'] ?? 'location') === 'location')
            ->pluck('remote_location_id')
            ->filter()
            ->map(fn ($id) => (string) $id);

        if ($activeRemoteIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'mappings' => __('commerce.validation.duplicate_remote_location'),
            ]);
        }

        DB::transaction(function () use ($connection, $mappings): void {
            $storeIds = [];

            foreach ($mappings as $mapping) {
                $storeId = (int) $mapping['store_id'];
                $storeIds[] = $storeId;

                $connection->storeMappings()->updateOrCreate(
                    ['store_id' => $storeId],
                    [
                        'remote_location_id' => $mapping['remote_location_id'] ?: null,
                        'remote_location_name' => $mapping['remote_location_name'] ?: null,
                        'inventory_mode' => $mapping['inventory_mode'],
                        'fulfillment_priority' => (int) $mapping['fulfillment_priority'],
                        'safety_stock' => $mapping['safety_stock'],
                        'sync_inventory' => (bool) ($mapping['sync_inventory'] ?? false),
                        'fulfill_orders' => (bool) ($mapping['fulfill_orders'] ?? false),
                        'is_active' => (bool) ($mapping['is_active'] ?? false),
                    ],
                );
            }

            $connection->storeMappings()->whereNotIn('store_id', $storeIds)->delete();
        });
    }
}
