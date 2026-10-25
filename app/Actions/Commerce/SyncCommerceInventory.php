<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Models\CommerceStoreMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Exceptions\CommerceRemoteEntityMissing;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;

final readonly class SyncCommerceInventory
{
    public function __construct(
        private CommerceProviderRegistry $providers,
        private SyncCommerceProduct $products,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(CommerceConnection $connection, array $payload): void
    {
        $this->sync($connection, $payload, true);
    }

    /** @param array<string, mixed> $payload */
    private function sync(CommerceConnection $connection, array $payload, bool $recoverMissingEntity): void
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $variantId = filled($payload['variant_id'] ?? null) ? (int) $payload['variant_id'] : null;
        $storeId = (int) ($payload['store_id'] ?? 0);
        if (! $productId || ! $storeId) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.inventory_payload_invalid'));
        }
        if (! Product::query()->whereKey($productId)->exists()) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.product_missing'));
        }
        if ($variantId && ! ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $productId)
            ->exists()) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.product_missing'));
        }

        $storeMapping = $connection->storeMappings()->active()
            ->where('store_id', $storeId)
            ->where('sync_inventory', true)
            ->first();
        if (! $storeMapping || $storeMapping->inventory_mode === CommerceStoreMapping::MODE_DISABLED) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.store_inventory_disabled'));
        }

        $localType = $variantId ? ProductVariant::class : Product::class;
        $localId = $variantId ?: $productId;
        $entity = $this->variantMapping($connection, $localType, $localId);
        if (! $entity) {
            $this->products->execute($connection, $productId, force: true);
            $entity = $this->variantMapping($connection, $localType, $localId);
        }
        if (! $entity) {
            throw new CommerceSyncException(__('commerce.sync.errors.product_mapping_missing'));
        }

        $mappings = $connection->storeMappings()->active()
            ->where('sync_inventory', true)
            ->where('inventory_mode', '!=', CommerceStoreMapping::MODE_DISABLED)
            ->get();
        if ($connection->provider === CommerceConnection::PROVIDER_WOOCOMMERCE) {
            $quantity = $this->availableForMappings($mappings, $productId, $variantId);
            $levels = [[
                'remote_id' => $entity->remote_id,
                'remote_location_id' => null,
                'quantity' => $quantity,
                'metadata' => array_replace($entity->metadata ?? [], ['remote_parent_id' => $entity->remote_parent_id]),
            ]];
        } else {
            $sameRemoteLocation = $mappings->where('remote_location_id', $storeMapping->remote_location_id);
            $quantity = $storeMapping->inventory_mode === CommerceStoreMapping::MODE_AGGREGATE
                ? $this->availableForMappings($sameRemoteLocation, $productId, $variantId)
                : $this->availableForMappings(collect([$storeMapping]), $productId, $variantId);
            $levels = [[
                'remote_id' => $entity->remote_id,
                'remote_location_id' => $storeMapping->remote_location_id,
                'quantity' => $quantity,
                'metadata' => $entity->metadata ?? [],
            ]];
        }

        try {
            $this->providers->get($connection->provider)->setInventory($connection, $levels);
        } catch (CommerceRemoteEntityMissing $e) {
            if (! $recoverMissingEntity) {
                throw $e;
            }

            $this->products->execute($connection, $productId, force: true);
            $this->sync($connection, $payload, false);
        }
    }

    private function variantMapping(CommerceConnection $connection, string $localType, int $localId): ?CommerceEntityMapping
    {
        return CommerceEntityMapping::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->where('entity_type', CommerceEntityMapping::TYPE_VARIANT)
            ->where('local_type', $localType)
            ->where('local_id', $localId)
            ->first();
    }

    /** @param iterable<CommerceStoreMapping> $mappings */
    private function availableForMappings(iterable $mappings, int $productId, ?int $variantId): int
    {
        $total = '0';
        foreach ($mappings as $mapping) {
            $level = StockLevel::query()
                ->where('store_id', $mapping->store_id)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->first();
            $available = bcsub(
                bcsub((string) ($level?->quantity ?? 0), (string) ($level?->reserved_quantity ?? 0), 4),
                (string) $mapping->safety_stock,
                4,
            );
            if (bccomp($available, '0', 4) > 0) {
                $total = bcadd($total, $available, 4);
            }
        }

        return max(0, (int) floor((float) $total));
    }
}
