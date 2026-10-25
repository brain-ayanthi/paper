<?php

namespace App\Support\Inventory;

use App\Models\ProductBatch;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

class StockLevelBatchDataset
{
    /** @param Collection<int, StockLevel> $levels */
    public function load(Collection $levels): Collection
    {
        $tracked = $levels->filter(fn (StockLevel $level) => (bool) $level->product?->track_batches);

        if ($tracked->isEmpty()) {
            return collect();
        }

        return ProductBatch::query()
            ->whereIn('store_id', $tracked->pluck('store_id')->unique())
            ->whereIn('product_id', $tracked->pluck('product_id')->unique())
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('batch_number')
            ->get()
            ->groupBy(fn (ProductBatch $batch) => $this->key(
                (int) $batch->store_id,
                (int) $batch->product_id,
                $batch->variant_id !== null ? (int) $batch->variant_id : null,
            ));
    }

    public function batchesFor(StockLevel $level, Collection $groups): Collection
    {
        return $groups->get($this->key(
            (int) $level->store_id,
            (int) $level->product_id,
            $level->variant_id !== null ? (int) $level->variant_id : null,
        ), collect());
    }

    public function unallocated(StockLevel $level, Collection $batches): string
    {
        $batchQuantity = $batches->reduce(
            fn (string $total, ProductBatch $batch) => bcadd($total, (string) $batch->quantity, 4),
            '0.0000',
        );

        return bcsub((string) $level->quantity, $batchQuantity, 4);
    }

    public function value(StockLevel $level, Collection $batches): string
    {
        $batchValue = $batches->reduce(function (string $total, ProductBatch $batch) use ($level) {
            $cost = $batch->cost_price ?? $level->weighted_average_cost ?? '0';

            return bcadd($total, bcmul((string) $batch->quantity, (string) $cost, 4), 4);
        }, '0.0000');

        return bcadd(
            $batchValue,
            bcmul($this->unallocated($level, $batches), (string) ($level->weighted_average_cost ?? '0'), 4),
            4,
        );
    }

    private function key(int $storeId, int $productId, ?int $variantId): string
    {
        return $storeId.':'.$productId.':'.($variantId ?? '');
    }
}
