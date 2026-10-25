<?php

namespace App\Services\Inventory;

use App\Models\PosPaymentSession;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;

/** Read durable exact-batch reservations held by kiosk orders and checkouts. */
class KioskBatchReservations
{
    /** @param  array<int, int>  $batchIds @return array<int, string> */
    public function forBatches(array $batchIds, ?string $exceptSessionUuid = null): array
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        $totals = array_fill_keys($batchIds, '0.0000');
        if ($batchIds === []) {
            return $totals;
        }

        $placed = SaleItem::query()
            ->selectRaw('batch_id, SUM(quantity) AS reserved_quantity')
            ->whereIn('batch_id', $batchIds)
            ->whereHas('sale', fn ($query) => $query->whereIn('status', [
                Sale::STATUS_PLACED,
                Sale::STATUS_HELD,
            ]))
            ->groupBy('batch_id')
            ->pluck('reserved_quantity', 'batch_id');
        foreach ($placed as $batchId => $quantity) {
            $totals[(int) $batchId] = (string) $quantity;
        }

        foreach ($this->activeSessions($exceptSessionUuid) as $session) {
            $reservation = (array) data_get($session->cart_summary, '_kiosk_reservation', []);
            if (($reservation['state'] ?? null) !== 'active') {
                continue;
            }
            foreach ((array) ($reservation['lines'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $batchId = (int) ($line['batch_id'] ?? 0);
                if (array_key_exists($batchId, $totals)) {
                    $totals[$batchId] = bcadd($totals[$batchId], (string) ($line['quantity'] ?? '0'), 4);
                }
            }
        }

        return $totals;
    }

    public function forBatch(int $batchId, ?string $exceptSessionUuid = null): string
    {
        return $this->forBatches([$batchId], $exceptSessionUuid)[$batchId] ?? '0.0000';
    }

    public function forStock(
        int $storeId,
        int $productId,
        ?int $variantId,
        ?string $exceptSessionUuid = null,
    ): string {
        return $this->sessionQuantity(
            static fn (array $line, PosPaymentSession $session): bool => (int) $session->store_id === $storeId
                && (int) ($line['product_id'] ?? 0) === $productId
                && self::nullableInt($line['variant_id'] ?? null) === $variantId,
            $exceptSessionUuid,
        );
    }

    public function availableForBatch(ProductBatch $batch, ?string $exceptSessionUuid = null): string
    {
        $available = bcsub(
            (string) $batch->quantity,
            $this->forBatch((int) $batch->id, $exceptSessionUuid),
            4,
        );

        return bccomp($available, '0', 4) < 0 ? '0.0000' : $available;
    }

    /**
     * @param  callable(array<string, mixed>, PosPaymentSession): bool  $matches
     */
    private function sessionQuantity(callable $matches, ?string $exceptSessionUuid): string
    {
        $total = '0.0000';
        foreach ($this->activeSessions($exceptSessionUuid) as $session) {
            $reservation = (array) data_get($session->cart_summary, '_kiosk_reservation', []);
            if (($reservation['state'] ?? null) !== 'active') {
                continue;
            }
            foreach ((array) ($reservation['lines'] ?? []) as $line) {
                if (is_array($line) && $matches($line, $session)) {
                    $total = bcadd($total, (string) ($line['quantity'] ?? '0'), 4);
                }
            }
        }

        return $total;
    }

    /** @return Collection<int, PosPaymentSession> */
    private function activeSessions(?string $exceptSessionUuid = null): Collection
    {
        return PosPaymentSession::query()
            ->whereIn('status', [
                PosPaymentSession::STATUS_PENDING,
                PosPaymentSession::STATUS_SELECTED,
                PosPaymentSession::STATUS_PAID,
            ])
            ->where(function ($query) {
                $query->where('status', PosPaymentSession::STATUS_PAID)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereNotNull('cart_summary')
            ->when($exceptSessionUuid !== null, fn ($query) => $query->where('uuid', '!=', $exceptSessionUuid))
            ->get();
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
