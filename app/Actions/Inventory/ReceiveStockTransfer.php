<?php

namespace App\Actions\Inventory;

use App\Models\StockTransfer;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a stock transfer from `in_transit` → `received`.
 *
 * Posts a `transfer_in` movement for every line item against the
 * destination store using the entered received quantities, which may
 * be a full or partial delivery. Receipt overages are rejected because
 * they were never deducted from the source store.
 *
 * Short receipts are reconciled: any quantity that was dispatched but not
 * received is returned to the SOURCE store (a `transfer_in` back to it), so
 * the source isn't silently left short for goods that never arrived. The
 * shortfall stays visible on the document (requested vs received) and shows
 * up in the stock-movement ledger.
 */
class ReceiveStockTransfer
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
    ) {}

    /**
     * @param  array<int, array{id: int, received_quantity: string|float}>  $receivedQtys
     *         Maps StockTransferItem IDs to their received quantities.
     */
    public function __invoke(StockTransfer $transfer, array $receivedQtys, ?User $user = null): StockTransfer
    {
        abort_unless($transfer->isInTransit(), 422, 'Transfer must be in transit to receive.');

        return DB::transaction(function () use ($transfer, $receivedQtys, $user) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            abort_unless($transfer->isInTransit(), 422, 'Transfer must be in transit to receive.');

            $items = $transfer->items()->with('product:id,name')->lockForUpdate()->get();
            $transfer->setRelation('items', $items);

            $submittedIds = collect($receivedQtys)
                ->map(fn ($row) => is_array($row)
                    && isset($row['id'])
                    && preg_match('/^\d+$/D', (string) $row['id']) === 1
                        ? (int) $row['id']
                        : null);
            $expectedIds = $items->pluck('id')->map(fn ($id) => (int) $id);

            if ($submittedIds->contains(null)
                || $submittedIds->count() !== $expectedIds->count()
                || $submittedIds->unique()->count() !== $submittedIds->count()
                || $submittedIds->sort()->values()->all() !== $expectedIds->sort()->values()->all()) {
                throw new \DomainException(__('inventory.transfers.errors.receipt_items_mismatch'));
            }

            do_action('stock_transfer.before_receive', $transfer);

            // The exact one-to-one ID set is now proven, so lookup cannot
            // silently substitute a requested quantity for an omitted line.
            $qtyMap = collect($receivedQtys)->keyBy(fn ($row) => (int) $row['id']);

            foreach ($transfer->items as $item) {
                $requested   = (string) $item->requested_quantity;
                $receivedQty = (string) ($qtyMap->get($item->id)['received_quantity'] ?? '');
                $productLabel = $item->product?->name ?? "Product #{$item->product_id}";

                if (preg_match('/^\d+(?:\.\d+)?$/D', $receivedQty) !== 1
                    || bccomp($receivedQty, '0', 4) < 0) {
                    throw new \DomainException(__('inventory.transfers.errors.receipt_quantity_invalid', [
                        'product' => $productLabel,
                    ]));
                }

                if (bccomp($receivedQty, $requested, 4) > 0) {
                    throw new \DomainException(__('inventory.transfers.errors.receipt_overage', [
                        'product' => $productLabel,
                        'requested' => $requested,
                    ]));
                }

                $item->update(['received_quantity' => $receivedQty]);

                // 1. What actually arrived lands at the destination store.
                if (bccomp($receivedQty, '0', 4) > 0) {
                    $destinationBatchId = $this->destinationBatchId($transfer, $item, $receivedQty);
                    ($this->recordMovement)(
                        storeId:       $transfer->to_store_id,
                        productId:     $item->product_id,
                        variantId:     $item->variant_id,
                        batchId:       $destinationBatchId,
                        quantityDelta: $receivedQty,
                        type:          'transfer_in',
                        referenceType: StockTransfer::class,
                        referenceId:   $transfer->id,
                        unitCost:      $item->unit_cost !== null ? (string) $item->unit_cost : null,
                        notes:         "Received: {$transfer->number}",
                        createdBy:     $user?->id,
                    );
                }

                // 2. Anything dispatched but not received goes BACK to the source
                //    store, so it isn't silently lost.
                $shortfall = bcsub($requested, $receivedQty, 4);
                if (bccomp($shortfall, '0', 4) > 0) {
                    ($this->recordMovement)(
                        storeId:       $transfer->from_store_id,
                        productId:     $item->product_id,
                        variantId:     $item->variant_id,
                        batchId:       $item->batch_id,
                        quantityDelta: $shortfall,
                        type:          'transfer_in',
                        referenceType: StockTransfer::class,
                        referenceId:   $transfer->id,
                        unitCost:      $item->unit_cost !== null ? (string) $item->unit_cost : null,
                        notes:         "Returned to source (short receipt): {$transfer->number}",
                        createdBy:     $user?->id,
                    );
                }
            }

            $transfer->update([
                'status'        => StockTransfer::STATUS_RECEIVED,
                'received_date' => Carbon::now()->toDateString(),
                'updated_by'    => $user?->id,
            ]);

            do_action('stock_transfer.after_receive', $transfer);

            return $transfer->fresh();
        });
    }

    private function destinationBatchId(StockTransfer $transfer, mixed $item, string $receivedQty): ?int
    {
        if (! $item->batch_id) {
            return null;
        }

        if (! $item->batch_number_snapshot) {
            throw new \DomainException(__('inventory.transfers.errors.batch_snapshot_missing', [
                'product' => $item->product?->name ?? "Product #{$item->product_id}",
            ]));
        }

        $snapshot = [
            'batch_number' => $item->batch_number_snapshot,
            'manufacture_date' => $item->batch_manufacture_date_snapshot?->toDateString(),
            'expiry_date' => $item->batch_expiry_date_snapshot?->toDateString(),
            'cost_price' => $item->batch_cost_price_snapshot,
            'selling_price' => $item->batch_selling_price_snapshot,
            'mrp' => $item->batch_mrp_snapshot,
        ];

        $destination = ProductBatch::query()
            ->withTrashed()
            ->where('store_id', $transfer->to_store_id)
            ->where('product_id', $item->product_id)
            ->where('variant_id', $item->variant_id)
            ->where('batch_number', $snapshot['batch_number'])
            ->lockForUpdate()
            ->first();

        if (! $destination) {
            $destination = ProductBatch::create([
                'store_id' => $transfer->to_store_id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'batch_number' => $snapshot['batch_number'],
                'manufacture_date' => $snapshot['manufacture_date'],
                'expiry_date' => $snapshot['expiry_date'],
                'cost_price' => $snapshot['cost_price'],
                'selling_price' => $snapshot['selling_price'],
                'mrp' => $snapshot['mrp'],
                'quantity' => 0,
                'initial_quantity' => $receivedQty,
            ]);
        } else {
            $conflicts = collect(['manufacture_date', 'expiry_date', 'cost_price', 'selling_price', 'mrp'])
                ->contains(fn ($field) => $this->batchValue($destination, $field) !== $this->batchValue($snapshot, $field));

            if ($conflicts) {
                throw new \DomainException(__('inventory.transfers.errors.destination_batch_conflict', [
                    'batch' => $snapshot['batch_number'],
                ]));
            }

            if ($destination->trashed()) {
                $destination->restore();
            }
        }

        return (int) $destination->id;
    }

    private function batchValue(mixed $source, string $field): ?string
    {
        $value = is_array($source) ? ($source[$field] ?? null) : $source->{$field};

        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['manufacture_date', 'expiry_date'], true)) {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
        }

        return bcadd((string) $value, '0', 4);
    }
}
