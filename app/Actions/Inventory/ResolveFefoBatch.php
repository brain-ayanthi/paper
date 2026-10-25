<?php

namespace App\Actions\Inventory;

use App\Actions\Sales\CompleteSale;
use App\Models\ProductBatch;
use App\Services\Inventory\KioskBatchReservations;

/**
 * Resolve the batch a sale line should draw from when the customer can't (or
 * shouldn't) pick one — FEFO: First-Expired, First-Out.
 *
 * The cashier lets staff pick a batch by hand; the self-serve kiosk has no
 * operator to do that, so it needs a deterministic server-side pick. This
 * returns the earliest-expiring LIVE batch (quantity > 0) for a
 * (store, product, variant), so short-dated stock clears first and per-batch
 * quantities stay in step with the stock level once
 * {@see RecordStockMovement} decrements it.
 *
 * Null-expiry batches never expire, so they sort LAST behind every dated
 * batch. When $excludeExpired is set (the company blocks expired-batch sales
 * and the actor lacks the `inventory.sell_expired` override) already-expired
 * batches are skipped entirely, matching the guard in {@see CompleteSale}.
 *
 * Returns null when no live batch qualifies. Sale completion may also require
 * one batch to cover the full requested quantity and lock the chosen row for
 * the duration of its transaction, preventing two tills from reserving the
 * same remaining units.
 */
class ResolveFefoBatch
{
    public function __construct(private readonly KioskBatchReservations $reservations) {}

    public function __invoke(
        int $storeId,
        int $productId,
        ?int $variantId,
        bool $excludeExpired = false,
        ?string $requiredQuantity = null,
        bool $lockForUpdate = false,
    ): ?ProductBatch {
        $query = ProductBatch::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->when(
                $requiredQuantity !== null && bccomp($requiredQuantity, '0', 4) > 0,
                fn ($q) => $q->where('quantity', '>=', $requiredQuantity),
                fn ($q) => $q->whereRaw('quantity > 0'),
            )
            ->when($excludeExpired, fn ($q) => $q->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', now()->toDateString());
            }))
            // Dated batches first (FEFO), null-expiry last; earliest expiry
            // wins, id breaks ties for a stable pick.
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $batches = $query->get();
        $reserved = $this->reservations->forBatches($batches->modelKeys());

        return $batches->first(function (ProductBatch $batch) use ($requiredQuantity, $reserved): bool {
            $available = bcsub((string) $batch->quantity, $reserved[(int) $batch->id] ?? '0', 4);

            return bccomp($available, $requiredQuantity ?? '0', 4) >= 0
                && bccomp($available, '0', 4) > 0;
        });
    }
}
