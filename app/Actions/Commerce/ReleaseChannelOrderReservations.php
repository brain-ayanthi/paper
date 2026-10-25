<?php

namespace App\Actions\Commerce;

use App\Models\ChannelOrder;
use App\Models\ChannelOrderAllocation;
use App\Models\StockLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ReleaseChannelOrderReservations
{
    public function __construct(private RecordCommerceOutboxEvent $outbox) {}

    public function execute(ChannelOrder $order): void
    {
        $changedLevels = DB::transaction(function () use ($order): Collection {
            $changed = collect();
            $allocations = $order->allocations()
                ->whereIn('status', [ChannelOrderAllocation::STATUS_PENDING, ChannelOrderAllocation::STATUS_RESERVED])
                ->with('items.orderItem')
                ->lockForUpdate()
                ->get();

            foreach ($allocations as $allocation) {
                foreach ($allocation->items as $allocationItem) {
                    $orderItem = $allocationItem->orderItem;
                    $quantity = bcsub(
                        (string) $allocationItem->quantity,
                        (string) $allocationItem->quantity_refunded,
                        4,
                    );
                    $level = StockLevel::query()
                        ->where('store_id', $allocation->store_id)
                        ->where('product_id', $orderItem->product_id)
                        ->where('variant_id', $orderItem->variant_id)
                        ->lockForUpdate()
                        ->first();
                    if ($level && bccomp($quantity, '0', 4) > 0) {
                        $level->reserved_quantity = bccomp((string) $level->reserved_quantity, $quantity, 4) >= 0
                            ? bcsub((string) $level->reserved_quantity, $quantity, 4)
                            : '0';
                        $level->save();
                        $changed->put($level->getKey(), $level);
                    }
                }
                $allocation->forceFill(['status' => ChannelOrderAllocation::STATUS_CANCELLED])->save();
            }

            $order->forceFill(['status' => ChannelOrder::STATUS_CANCELLED])->save();

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
                dedupeSource: 'channel-order-cancel-'.$order->getKey().'-level-'.$level->getKey(),
            );
        }
    }
}
