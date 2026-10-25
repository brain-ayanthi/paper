<?php

namespace App\Actions\Inventory;

use App\Models\ProductBatch;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves a stock transfer from `draft` → `in_transit`.
 *
 * Posts a `transfer_out` movement for every line item against the
 * source store, reducing its on-hand quantity. The destination store
 * is not touched until {@see ReceiveStockTransfer} runs.
 */
class DispatchStockTransfer
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
    ) {}

    public function __invoke(StockTransfer $transfer, ?User $user = null): StockTransfer
    {
        abort_unless($transfer->isDraft(), 422, 'Transfer must be in draft to dispatch.');

        return DB::transaction(function () use ($transfer, $user) {
            do_action('stock_transfer.before_dispatch', $transfer);

            $transfer->loadMissing('items.product');

            // Pre-flight: verify available stock for every item with a row lock
            // so concurrent dispatches cannot both "see" the same available qty.
            //
            // Aggregated per (product, variant) — code-review #68: lines
            // sharing a product were each checked against the FULL on-hand
            // independently, so two 6-qty lines against 10 on-hand both
            // passed and drove the source level to −2. The running
            // `$claimed` map makes later duplicate lines see only what the
            // earlier ones left.
            $errors = [];
            $claimed = [];
            $claimedBatches = [];
            $batchSnapshots = [];
            foreach ($transfer->items as $item) {
                $key = $item->product_id.':'.($item->variant_id ?? '');

                if ($item->product?->track_batches) {
                    if (! $item->batch_id) {
                        $errors[] = __('inventory.transfers.errors.batch_required_item', [
                            'product' => $item->product?->name ?? "Product #{$item->product_id}",
                        ]);

                        continue;
                    }

                    $batch = ProductBatch::query()
                        ->whereKey($item->batch_id)
                        ->lockForUpdate()
                        ->first();

                    $matchesLine = $batch
                        && (int) $batch->store_id === (int) $transfer->from_store_id
                        && (int) $batch->product_id === (int) $item->product_id
                        && ($batch->variant_id !== null ? (int) $batch->variant_id : null)
                            === ($item->variant_id !== null ? (int) $item->variant_id : null);

                    if (! $matchesLine) {
                        $errors[] = __('inventory.transfers.errors.invalid_batch_item', [
                            'product' => $item->product?->name ?? "Product #{$item->product_id}",
                        ]);

                        continue;
                    }

                    $batchKey = (int) $batch->id;
                    $available = bcsub(
                        (string) $batch->quantity,
                        $claimedBatches[$batchKey] ?? '0',
                        4,
                    );
                    $requested = (string) $item->requested_quantity;

                    if (bccomp($requested, $available, 4) > 0) {
                        $errors[] = __('inventory.transfers.errors.insufficient_batch_item', [
                            'product' => $item->product?->name ?? "Product #{$item->product_id}",
                            'batch' => $batch->batch_number,
                            'available' => $available,
                            'requested' => $requested,
                        ]);

                        continue;
                    }

                    $claimedBatches[$batchKey] = bcadd(
                        $claimedBatches[$batchKey] ?? '0',
                        $requested,
                        4,
                    );

                    $batchSnapshots[$item->id] = [
                        'batch_number_snapshot' => $batch->batch_number,
                        'batch_manufacture_date_snapshot' => $batch->manufacture_date?->toDateString(),
                        'batch_expiry_date_snapshot' => $batch->expiry_date?->toDateString(),
                        'batch_cost_price_snapshot' => $batch->cost_price,
                        'batch_selling_price_snapshot' => $batch->selling_price,
                        'batch_mrp_snapshot' => $batch->mrp,
                    ];

                    continue;
                }

                $level = StockLevel::query()
                    ->where('store_id', $transfer->from_store_id)
                    ->where('product_id', $item->product_id)
                    ->when(
                        $item->variant_id,
                        fn ($q) => $q->where('variant_id', $item->variant_id),
                        fn ($q) => $q->whereNull('variant_id')
                    )
                    ->lockForUpdate()
                    ->first(['quantity']);

                $onHand = $level ? (string) $level->quantity : '0.0000';
                $available = bcsub($onHand, $claimed[$key] ?? '0', 4);
                $requested = (string) $item->requested_quantity;

                if (bccomp($requested, $available, 4) > 0) {
                    $label = $item->product?->name ?? "Product #{$item->product_id}";
                    $errors[] = __('inventory.transfers.errors.insufficient_stock_item', [
                        'product' => $label,
                        'available' => $available,
                        'requested' => $requested,
                    ]);

                    continue;
                }

                $claimed[$key] = bcadd($claimed[$key] ?? '0', $requested, 4);
            }

            if ($errors !== []) {
                throw new \DomainException(
                    __('inventory.transfers.errors.insufficient_stock')."\n".implode("\n", $errors)
                );
            }

            foreach ($transfer->items as $item) {
                if (isset($batchSnapshots[$item->id])) {
                    $item->update($batchSnapshots[$item->id]);
                }

                ($this->recordMovement)(
                    storeId: $transfer->from_store_id,
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    batchId: $item->batch_id,
                    quantityDelta: bcmul((string) $item->requested_quantity, '-1', 4),
                    type: 'transfer_out',
                    referenceType: StockTransfer::class,
                    referenceId: $transfer->id,
                    unitCost: $item->unit_cost !== null ? (string) $item->unit_cost : null,
                    notes: "Dispatched: {$transfer->number}",
                    createdBy: $user?->id,
                );
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'updated_by' => $user?->id,
            ]);

            do_action('stock_transfer.after_dispatch', $transfer);

            return $transfer->fresh();
        });
    }
}
