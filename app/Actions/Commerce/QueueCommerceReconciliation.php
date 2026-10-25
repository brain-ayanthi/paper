<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class QueueCommerceReconciliation
{
    /** V3 replays catalog data with primary image, category, and brand fields. */
    private const PRODUCT_SYNC_VERSION = 3;

    /** V3 replays stock after product upserts began scheduling a final inventory pass. */
    private const INVENTORY_SYNC_VERSION = 3;

    /** V4 applies the same post-product inventory guarantee to Shopify. */
    private const SHOPIFY_INVENTORY_SYNC_VERSION = 4;

    /** @return array{products:int,customers:int,inventory:int} */
    public function execute(CommerceConnection $connection, bool $forceProducts = false): array
    {
        $counts = ['products' => 0, 'customers' => 0, 'inventory' => 0];
        if (! Schema::hasTable('commerce_outbox_events')) {
            return $counts;
        }

        if ((bool) data_get($connection->settings, 'sync_products', false)) {
            $catalogRun = $forceProducts ? (string) Str::uuid() : null;
            Product::query()->withTrashed()->select(['id', 'updated_at'])->chunkById(250, function ($products) use ($connection, $forceProducts, $catalogRun, &$counts): void {
                $counts['products'] += $this->queueBatch(
                    $connection,
                    CommerceOutboxEvent::TYPE_PRODUCT_SYNC,
                    $products,
                    fn () => $forceProducts ? ['force' => true] : [],
                    fn ($product) => $forceProducts
                        ? 'catalog-manual-'.$catalogRun.'-'.$product->getKey()
                        : 'catalog-v'.self::PRODUCT_SYNC_VERSION.'-'.$this->version($product),
                );
            });
        }

        if ((bool) data_get($connection->settings, 'sync_customers', false)) {
            Customer::query()->withTrashed()->select(['id', 'updated_at'])->chunkById(250, function ($customers) use ($connection, &$counts): void {
                $counts['customers'] += $this->queueBatch(
                    $connection,
                    CommerceOutboxEvent::TYPE_CUSTOMER_SYNC,
                    $customers,
                    fn () => [],
                    fn ($customer) => 'customer-'.$this->version($customer),
                );
            });
        }

        $storeIds = $connection->storeMappings()->active()
            ->where('sync_inventory', true)
            ->where('inventory_mode', '!=', 'disabled')
            ->pluck('store_id');
        if ($storeIds->isNotEmpty()) {
            StockLevel::query()
                ->whereIn('store_id', $storeIds)
                ->whereHas('product')
                ->where(fn ($query) => $query
                    ->whereNull('variant_id')
                    ->orWhereHas('variant'))
                ->select(['id', 'store_id', 'product_id', 'variant_id', 'updated_at'])
                ->chunkById(250, function ($levels) use ($connection, &$counts): void {
                    $counts['inventory'] += $this->queueBatch(
                        $connection,
                        CommerceOutboxEvent::TYPE_INVENTORY_SYNC,
                        $levels,
                        fn ($level) => [
                            'store_id' => $level->store_id,
                            'product_id' => $level->product_id,
                            'variant_id' => $level->variant_id,
                        ],
                        fn ($level) => 'stock-v'.$this->inventorySyncVersion($connection).'-'.$this->version($level),
                    );
                });
        }

        return $counts;
    }

    /**
     * @param  iterable<Model>  $aggregates
     * @param  callable(Model):array<string, mixed>  $payload
     * @param  callable(Model):string  $source
     */
    private function queueBatch(
        CommerceConnection $connection,
        string $eventType,
        iterable $aggregates,
        callable $payload,
        callable $source,
    ): int {
        $timestamp = now('UTC');
        $rows = [];
        foreach ($aggregates as $aggregate) {
            $eventPayload = $payload($aggregate);
            $sourceValue = $source($aggregate);
            $rows[] = [
                'commerce_connection_id' => $connection->getKey(),
                'event_type' => $eventType,
                'aggregate_type' => $aggregate::class,
                'aggregate_id' => $aggregate->getKey(),
                'dedupe_key' => mb_substr(
                    "reconcile:{$eventType}:{$aggregate->getKey()}:{$sourceValue}",
                    0,
                    191,
                ),
                'payload' => $eventPayload === []
                    ? null
                    : Crypt::encryptString(json_encode(
                        $eventPayload,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    )),
                'status' => CommerceOutboxEvent::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now('UTC'),
                'processed_at' => null,
                'last_error' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $dedupeKeys = array_column($rows, 'dedupe_key');
        $failed = CommerceOutboxEvent::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->whereIn('dedupe_key', $dedupeKeys)
            ->where('status', CommerceOutboxEvent::STATUS_FAILED);
        $requeued = (clone $failed)->count();
        if ($requeued > 0) {
            $failed->update([
                'status' => CommerceOutboxEvent::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $timestamp,
                'processed_at' => null,
                'last_error' => null,
                'updated_at' => $timestamp,
            ]);
        }

        $inserted = 0;
        foreach (array_chunk($rows, 50) as $chunk) {
            $inserted += CommerceOutboxEvent::query()->insertOrIgnore($chunk);
        }

        return $inserted + $requeued;
    }

    private function version(Model $model): string
    {
        return $model->updated_at?->format('YmdHis.u') ?? 'initial';
    }

    private function inventorySyncVersion(CommerceConnection $connection): int
    {
        return $connection->provider === CommerceConnection::PROVIDER_SHOPIFY
            ? self::SHOPIFY_INVENTORY_SYNC_VERSION
            : self::INVENTORY_SYNC_VERSION;
    }
}
