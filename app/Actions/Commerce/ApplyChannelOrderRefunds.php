<?php

namespace App\Actions\Commerce;

use App\Actions\Sales\RecordSaleReturn;
use App\Models\ChannelOrder;
use App\Models\ChannelOrderAllocation;
use App\Models\ChannelOrderAllocationItem;
use App\Models\ReturnReason;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Mirrors cumulative provider refunds into store-scoped POS sale returns. */
final readonly class ApplyChannelOrderRefunds
{
    public function __construct(
        private RecordSaleReturn $recordReturn,
        private RecordCommerceOutboxEvent $outbox,
    ) {}

    /** @return array{returns:int,quantity:string,amount:string} */
    public function execute(ChannelOrder $order): array
    {
        $changedLevels = collect();

        $result = DB::transaction(function () use ($order, &$changedLevels): array {
            $order = ChannelOrder::query()
                ->with([
                    'connection',
                    'items',
                    'allocations' => fn ($query) => $query->orderBy('fulfilled_at')->orderBy('id'),
                    'allocations.items.orderItem',
                    'allocations.sale.items',
                    'allocations.sale.payments',
                ])
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $fulfilled = $order->allocations
                ->where('status', ChannelOrderAllocation::STATUS_FULFILLED)
                ->filter(fn (ChannelOrderAllocation $allocation) => $allocation->sale !== null);
            $hasFulfilled = $fulfilled->isNotEmpty();
            if (! $hasFulfilled) {
                return ['returns' => 0, 'quantity' => '0.0000', 'amount' => '0.0000'];
            }

            $plans = [];
            $quantityApplied = '0';
            foreach ($order->items as $orderItem) {
                $allocationItems = $order->allocations
                    ->flatMap(fn (ChannelOrderAllocation $allocation) => $allocation->items)
                    ->where('channel_order_item_id', $orderItem->getKey())
                    ->filter(fn (ChannelOrderAllocationItem $item) => ($item->allocation->status === ChannelOrderAllocation::STATUS_FULFILLED && $item->allocation->sale)
                        || in_array($item->allocation->status, [
                            ChannelOrderAllocation::STATUS_PENDING,
                            ChannelOrderAllocation::STATUS_RESERVED,
                        ], true)
                    );
                $alreadyApplied = $allocationItems->reduce(
                    fn (string $total, ChannelOrderAllocationItem $item) => bcadd($total, (string) $item->quantity_refunded, 4),
                    '0',
                );
                $remaining = bcsub((string) $orderItem->quantity_refunded, $alreadyApplied, 4);
                if (bccomp($remaining, '0', 4) <= 0) {
                    continue;
                }

                $candidates = $allocationItems->sortBy(function (ChannelOrderAllocationItem $item): string {
                    $allocation = $item->allocation;
                    $rank = $allocation->status === ChannelOrderAllocation::STATUS_FULFILLED ? '0' : '1';

                    return $rank.'|'.str_pad((string) $allocation->getKey(), 20, '0', STR_PAD_LEFT);
                });

                foreach ($candidates as $allocationItem) {
                    if (bccomp($remaining, '0', 4) <= 0) {
                        break;
                    }
                    $available = bcsub(
                        (string) $allocationItem->quantity,
                        (string) $allocationItem->quantity_refunded,
                        4,
                    );
                    if (bccomp($available, '0', 4) <= 0) {
                        continue;
                    }
                    $delta = bccomp($available, $remaining, 4) <= 0 ? $available : $remaining;
                    $allocation = $allocationItem->allocation;

                    if ($allocation->status === ChannelOrderAllocation::STATUS_FULFILLED && $allocation->sale) {
                        $plans[$allocation->getKey()] ??= [
                            'allocation' => $allocation,
                            'deltas' => [],
                            'targets' => [],
                            'additional' => '0',
                        ];
                        $plans[$allocation->getKey()]['deltas'][] = [$allocationItem, $delta];
                        $plans[$allocation->getKey()]['targets'][$allocationItem->getKey()] = bcadd(
                            (string) $allocationItem->quantity_refunded,
                            $delta,
                            4,
                        );
                    } elseif (in_array($allocation->status, [
                        ChannelOrderAllocation::STATUS_PENDING,
                        ChannelOrderAllocation::STATUS_RESERVED,
                    ], true)) {
                        $this->releaseOpenReservation($allocationItem, $delta, $changedLevels);
                    }

                    $quantityApplied = bcadd($quantityApplied, $delta, 4);
                    $remaining = bcsub($remaining, $delta, 4);
                }
            }

            foreach ($order->allocations->whereIn('status', [
                ChannelOrderAllocation::STATUS_PENDING,
                ChannelOrderAllocation::STATUS_RESERVED,
            ]) as $allocation) {
                $this->refreshOpenAllocationTotals($allocation);
            }

            $alreadyRecorded = $this->recordedRefundTotal($order);
            $amountRemaining = bcsub((string) $order->refunded_total, $alreadyRecorded, 4);
            if (bccomp($amountRemaining, '0', 4) < 0) {
                $amountRemaining = '0';
            }

            $predictedLines = '0';
            foreach ($plans as &$plan) {
                [$plan['items'], $plan['line_total']] = $this->returnLines($plan['allocation'], $plan['deltas']);
                $predictedLines = bcadd($predictedLines, $plan['line_total'], 4);
            }
            unset($plan);

            $additional = bcsub($amountRemaining, $predictedLines, 4);
            if (bccomp($additional, '0', 4) < 0) {
                $additional = '0';
            }
            if (bccomp($additional, '0', 4) > 0) {
                $additionalAllocation = collect($plans)->first(
                    fn (array $plan) => bccomp((string) $plan['allocation']->shipping_total, '0', 4) > 0,
                );
                if (! $additionalAllocation) {
                    $allocation = $fulfilled->first();
                    $plans[$allocation->getKey()] ??= [
                        'allocation' => $allocation,
                        'deltas' => [],
                        'targets' => [],
                        'items' => [],
                        'line_total' => '0',
                        'additional' => '0',
                    ];
                    $additionalAllocation = $plans[$allocation->getKey()];
                }
                $plans[$additionalAllocation['allocation']->getKey()]['additional'] = $additional;
            }

            $reason = ReturnReason::query()->where('code', 'other')->where('is_active', true)->first()
                ?? ReturnReason::query()->where('is_active', true)->ordered()->first();
            if (! $reason && $plans !== []) {
                throw new CommerceSyncException(__('commerce.orders.errors.refund_reason_missing'), false);
            }

            $returnCount = 0;
            $amountApplied = '0';
            foreach ($plans as $plan) {
                if ($plan['items'] === [] && bccomp((string) $plan['additional'], '0', 4) <= 0) {
                    continue;
                }
                $allocation = $plan['allocation'];
                $sale = $allocation->sale;
                $actor = User::query()->find($sale->cashier_id);
                if (! $actor) {
                    throw new CommerceSyncException(__('commerce.orders.errors.refund_actor_missing'), false);
                }
                $marker = $this->marker($order, $allocation);
                $state = json_encode([
                    'targets' => $plan['targets'],
                    'provider_total' => (string) $order->refunded_total,
                    'additional' => (string) $plan['additional'],
                ], JSON_THROW_ON_ERROR);

                $return = ($this->recordReturn)([
                    'sale_id' => $sale->getKey(),
                    'reason_code_id' => $reason->getKey(),
                    'refund_method_id' => $sale->payments->first()?->payment_method_id,
                    'restock' => true,
                    'additional_refund_amount' => (string) $plan['additional'],
                    'return_date' => ($order->remote_updated_at ?? $order->cancelled_at ?? now())->toDateString(),
                    'notes' => __('commerce.orders.refund_note', [
                        'channel' => $order->connection->name,
                        'order' => $order->remote_order_number ?: $order->remote_order_id,
                    ])."\n{$marker}",
                    'client_uuid' => $this->deterministicUuid($marker.'|'.$state),
                    'items' => $plan['items'],
                ], $actor);

                foreach ($plan['targets'] as $allocationItemId => $target) {
                    ChannelOrderAllocationItem::query()->whereKey($allocationItemId)->update([
                        'quantity_refunded' => $target,
                    ]);
                }
                $returnCount++;
                $amountApplied = bcadd($amountApplied, (string) $return->grand_total, 4);
            }

            return [
                'returns' => $returnCount,
                'quantity' => bcadd($quantityApplied, '0', 4),
                'amount' => bcadd($amountApplied, '0', 4),
            ];
        });

        foreach ($changedLevels as $level) {
            $this->outbox->execute(
                'inventory.sync',
                $level,
                [
                    'store_id' => $level->store_id,
                    'product_id' => $level->product_id,
                    'variant_id' => $level->variant_id,
                ],
                storeId: (int) $level->store_id,
                dedupeSource: 'channel-refund-'.$order->getKey().'-level-'.$level->getKey().'-'.$level->updated_at?->format('Uu'),
            );
        }

        return $result;
    }

    /** @param Collection<int, StockLevel> $changedLevels */
    private function releaseOpenReservation(
        ChannelOrderAllocationItem $item,
        string $delta,
        Collection $changedLevels,
    ): void {
        $orderItem = $item->orderItem;
        $level = StockLevel::query()
            ->where('store_id', $item->allocation->store_id)
            ->where('product_id', $orderItem->product_id)
            ->where('variant_id', $orderItem->variant_id)
            ->lockForUpdate()
            ->first();
        if ($level) {
            $level->reserved_quantity = bccomp((string) $level->reserved_quantity, $delta, 4) >= 0
                ? bcsub((string) $level->reserved_quantity, $delta, 4)
                : '0';
            $level->save();
            $changedLevels->put($level->getKey(), $level);
        }
        $item->forceFill([
            'quantity_refunded' => bcadd((string) $item->quantity_refunded, $delta, 4),
        ])->save();
    }

    private function refreshOpenAllocationTotals(ChannelOrderAllocation $allocation): void
    {
        $allocation->loadMissing('items.orderItem');
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $hasQuantity = false;
        foreach ($allocation->items as $item) {
            $quantity = bcsub((string) $item->quantity, (string) $item->quantity_refunded, 4);
            if (bccomp($quantity, '0', 4) <= 0) {
                continue;
            }
            $hasQuantity = true;
            $orderItem = $item->orderItem;
            $ratio = bcdiv($quantity, (string) $orderItem->quantity, 8);
            $subtotal = bcmul((string) $orderItem->unit_price, $quantity, 4);
            $discount = bcmul((string) $orderItem->discount_amount, $ratio, 4);
            $tax = bcmul((string) $orderItem->tax_amount, $ratio, 4);
            $lineTotal = bcmul((string) $orderItem->line_total, $ratio, 4);
            $totals['subtotal'] = bcadd($totals['subtotal'], $subtotal, 4);
            $totals['discount'] = bcadd($totals['discount'], $discount, 4);
            $totals['tax'] = bcadd($totals['tax'], $tax, 4);
            $totals['total'] = bcadd($totals['total'], bcadd($lineTotal, $tax, 4), 4);
        }

        $shipping = $hasQuantity ? (string) $allocation->shipping_total : '0';
        $allocation->forceFill([
            'status' => $hasQuantity ? $allocation->status : ChannelOrderAllocation::STATUS_CANCELLED,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount'],
            'tax_total' => $totals['tax'],
            'shipping_total' => $shipping,
            'grand_total' => bcadd($totals['total'], $shipping, 4),
        ])->save();
    }

    /**
     * @param  list<array{0:ChannelOrderAllocationItem,1:string}>  $deltas
     * @return array{0:list<array{sale_item_id:int,quantity:string,restock:bool,notes:string}>,1:string}
     */
    private function returnLines(ChannelOrderAllocation $allocation, array $deltas): array
    {
        $saleItems = $allocation->sale->items->sortBy(
            fn (SaleItem $item) => str_pad((string) $item->sort_order, 10, '0', STR_PAD_LEFT)
                .'|'.str_pad((string) $item->getKey(), 20, '0', STR_PAD_LEFT),
        );
        $available = $saleItems->mapWithKeys(fn (SaleItem $item) => [
            $item->getKey() => bcsub((string) $item->quantity, (string) $item->quantity_returned, 4),
        ])->all();
        $lines = [];
        $total = '0';

        foreach ($deltas as [$allocationItem, $delta]) {
            $remaining = $delta;
            $orderItem = $allocationItem->orderItem;
            $matches = $saleItems->filter(fn (SaleItem $item) => (int) $item->product_id === (int) $orderItem->product_id
                && (int) ($item->variant_id ?? 0) === (int) ($orderItem->variant_id ?? 0)
                && bccomp((string) $item->unit_price, (string) $orderItem->unit_price, 4) === 0
            );

            foreach ($matches as $saleItem) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }
                $itemAvailable = $available[$saleItem->getKey()] ?? '0';
                if (bccomp($itemAvailable, '0', 4) <= 0) {
                    continue;
                }
                $take = bccomp($itemAvailable, $remaining, 4) <= 0 ? $itemAvailable : $remaining;
                $lines[] = [
                    'sale_item_id' => (int) $saleItem->getKey(),
                    'quantity' => $take,
                    'restock' => true,
                    'notes' => __('commerce.orders.refund_item_note'),
                ];
                $ratio = bcdiv($take, (string) $saleItem->quantity, 8);
                $total = bcadd($total, bcadd(
                    bcmul((string) $saleItem->line_subtotal, $ratio, 4),
                    bcmul((string) $saleItem->tax_amount, $ratio, 4),
                    4,
                ), 4);
                $available[$saleItem->getKey()] = bcsub($itemAvailable, $take, 4);
                $remaining = bcsub($remaining, $take, 4);
            }

            if (bccomp($remaining, '0', 4) > 0) {
                throw new CommerceSyncException(__('commerce.orders.errors.refund_sale_line_missing', [
                    'sku' => $orderItem->sku_snapshot ?: $orderItem->product_name_snapshot,
                ]), false);
            }
        }

        return [$lines, $total];
    }

    private function recordedRefundTotal(ChannelOrder $order): string
    {
        $saleIds = $order->allocations->pluck('sale_id')->filter();
        if ($saleIds->isEmpty()) {
            return '0';
        }

        return bcadd((string) SaleReturn::query()
            ->whereIn('sale_id', $saleIds)
            ->where('status', SaleReturn::STATUS_COMPLETED)
            ->where('notes', 'like', '%[channel-refund:'.$order->commerce_connection_id.':'.$order->getKey().':%')
            ->sum('grand_total'), '0', 4);
    }

    private function marker(ChannelOrder $order, ChannelOrderAllocation $allocation): string
    {
        return '[channel-refund:'.$order->commerce_connection_id.':'.$order->getKey().':'.$allocation->getKey().']';
    }

    private function deterministicUuid(string $value): string
    {
        $hex = substr(hash('sha256', $value), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
