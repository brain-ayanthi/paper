<?php

namespace App\Actions\Commerce;

use App\Models\ChannelOrder;
use App\Models\ChannelOrderAllocation;
use App\Models\ChannelOrderItem;
use App\Models\CommerceStoreMapping;
use App\Models\StockLevel;
use App\Services\Commerce\CalculateUnpostedChannelOrderQuantity;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AllocateChannelOrder
{
    public function __construct(
        private RecordCommerceOutboxEvent $outbox,
        private CalculateUnpostedChannelOrderQuantity $unpostedQuantity,
    ) {}

    public function execute(ChannelOrder $order): void
    {
        $changedLevels = DB::transaction(function () use ($order): Collection {
            $lockedOrder = ChannelOrder::query()->lockForUpdate()->with('items')->findOrFail($order->getKey());
            if ($lockedOrder->allocations()->exists()) {
                return collect();
            }

            $mappings = CommerceStoreMapping::query()
                ->where('commerce_connection_id', $lockedOrder->commerce_connection_id)
                ->active()
                ->where('fulfill_orders', true)
                ->orderBy('fulfillment_priority')
                ->orderBy('store_id')
                ->get();
            if ($mappings->isEmpty()) {
                throw new CommerceSyncException(__('commerce.orders.errors.no_fulfillment_stores'));
            }

            $allocations = [];
            $changed = collect();
            foreach ($lockedOrder->items as $item) {
                if (! $item->product_id) {
                    throw new CommerceSyncException(__('commerce.orders.errors.product_not_mapped', ['sku' => $item->sku_snapshot ?: $item->product_name_snapshot]));
                }

                $remaining = $this->unpostedQuantity->execute(
                    (string) $item->quantity,
                    (string) $item->quantity_refunded,
                );
                if (bccomp($remaining, '0', 4) <= 0) {
                    continue;
                }
                foreach ($mappings as $mapping) {
                    if (bccomp($remaining, '0', 4) <= 0) {
                        break;
                    }
                    $level = StockLevel::query()
                        ->where('store_id', $mapping->store_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant_id', $item->variant_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $level) {
                        continue;
                    }

                    $available = bcsub(
                        bcsub((string) $level->quantity, (string) $level->reserved_quantity, 4),
                        (string) $mapping->safety_stock,
                        4,
                    );
                    if (bccomp($available, '0', 4) <= 0) {
                        continue;
                    }
                    $take = bccomp($available, $remaining, 4) >= 0 ? $remaining : $available;
                    $allocations[$mapping->store_id][] = [$item, $take];
                    $level->reserved_quantity = bcadd((string) $level->reserved_quantity, $take, 4);
                    $level->save();
                    $changed->put($level->getKey(), $level);
                    $remaining = bcsub($remaining, $take, 4);
                }

                if (bccomp($remaining, '0', 4) > 0) {
                    throw new CommerceSyncException(__('commerce.orders.errors.insufficient_stock', [
                        'sku' => $item->sku_snapshot ?: $item->product_name_snapshot,
                        'quantity' => $remaining,
                    ]));
                }
            }

            if ($allocations === []) {
                $lockedOrder->forceFill([
                    'status' => ChannelOrder::STATUS_COMPLETED,
                    'fulfillment_status' => 'fulfilled',
                    'last_error' => null,
                ])->save();

                return $changed->values();
            }

            $firstStoreId = array_key_first($allocations);
            foreach ($allocations as $storeId => $lines) {
                $totals = $this->totals($lines);
                $shipping = (int) $storeId === (int) $firstStoreId ? (string) $lockedOrder->shipping_total : '0';
                $allocation = $lockedOrder->allocations()->create([
                    'store_id' => $storeId,
                    'status' => ChannelOrderAllocation::STATUS_RESERVED,
                    'subtotal' => $totals['subtotal'],
                    'discount_total' => $totals['discount'],
                    'tax_total' => $totals['tax'],
                    'shipping_total' => $shipping,
                    'grand_total' => bcadd($totals['total'], $shipping, 4),
                    'reserved_at' => now('UTC'),
                ]);
                foreach ($lines as [$item, $quantity]) {
                    $allocation->items()->create([
                        'channel_order_item_id' => $item->getKey(),
                        'quantity' => $quantity,
                    ]);
                }
            }

            $lockedOrder->forceFill([
                'status' => ChannelOrder::STATUS_ALLOCATED,
                'fulfillment_status' => 'unfulfilled',
                'last_error' => null,
            ])->save();

            return $changed->values();
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
                dedupeSource: 'channel-order-'.$order->getKey().'-level-'.$level->getKey(),
            );
        }
    }

    /**
     * @param  list<array{0:ChannelOrderItem,1:string}>  $lines
     * @return array{subtotal:string,discount:string,tax:string,total:string}
     */
    private function totals(array $lines): array
    {
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        foreach ($lines as [$item, $quantity]) {
            $ratio = bcdiv($quantity, (string) $item->quantity, 8);
            $subtotal = bcmul((string) $item->unit_price, $quantity, 4);
            $discount = bcmul((string) $item->discount_amount, $ratio, 4);
            $tax = bcmul((string) $item->tax_amount, $ratio, 4);
            $lineTotal = bcmul((string) $item->line_total, $ratio, 4);
            $totals['subtotal'] = bcadd($totals['subtotal'], $subtotal, 4);
            $totals['discount'] = bcadd($totals['discount'], $discount, 4);
            $totals['tax'] = bcadd($totals['tax'], $tax, 4);
            $totals['total'] = bcadd($totals['total'], bcadd($lineTotal, $tax, 4), 4);
        }

        return $totals;
    }
}
