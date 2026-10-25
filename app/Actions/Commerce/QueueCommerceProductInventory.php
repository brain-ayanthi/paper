<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use App\Models\CommerceStoreMapping;
use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Support\Str;

/** Queues a final current-stock replay after a channel product upsert. */
final readonly class QueueCommerceProductInventory
{
    public function __construct(private RecordCommerceOutboxEvent $outbox) {}

    public function execute(CommerceConnection $connection, Product $product): int
    {
        $storeIds = $connection->storeMappings()
            ->active()
            ->where('sync_inventory', true)
            ->where('inventory_mode', '!=', CommerceStoreMapping::MODE_DISABLED)
            ->pluck('store_id');
        if ($storeIds->isEmpty()) {
            return 0;
        }

        $queued = 0;
        $source = 'product-followup-'.Str::uuid();
        StockLevel::query()
            ->whereIn('store_id', $storeIds)
            ->where('product_id', $product->getKey())
            ->where(fn ($query) => $query
                ->whereNull('variant_id')
                ->orWhereHas('variant'))
            ->chunkById(100, function ($levels) use ($connection, $source, &$queued): void {
                foreach ($levels as $level) {
                    $queued += count($this->outbox->execute(
                        CommerceOutboxEvent::TYPE_INVENTORY_SYNC,
                        $level,
                        [
                            'store_id' => (int) $level->store_id,
                            'product_id' => (int) $level->product_id,
                            'variant_id' => filled($level->variant_id) ? (int) $level->variant_id : null,
                            'source' => 'product.sync',
                        ],
                        storeId: (int) $level->store_id,
                        dedupeSource: $source,
                        connectionId: (int) $connection->getKey(),
                        coalescePending: true,
                    ));
                }
            });

        return $queued;
    }
}
