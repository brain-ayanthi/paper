<?php

namespace App\Actions\Commerce;

use App\Actions\Inventory\RecordStockMovement;
use App\Models\ChannelOrder;
use App\Models\ChannelOrderAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use Illuminate\Support\Facades\DB;

final readonly class FulfillChannelOrderAllocation
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private RecordCommerceOutboxEvent $outbox,
        private CreateChannelAllocationSale $createSale,
    ) {}

    public function execute(ChannelOrderAllocation $allocation, ?int $userId = null): ChannelOrderAllocation
    {
        return DB::transaction(function () use ($allocation, $userId): ChannelOrderAllocation {
            $locked = ChannelOrderAllocation::query()
                ->with(['order.connection', 'items.orderItem'])
                ->lockForUpdate()
                ->findOrFail($allocation->getKey());

            if ($locked->status === ChannelOrderAllocation::STATUS_FULFILLED) {
                return $locked;
            }
            if ($locked->status !== ChannelOrderAllocation::STATUS_RESERVED) {
                throw new CommerceSyncException(__('commerce.orders.errors.allocation_not_reserved'), false);
            }

            $actorId = $userId ?: auth()->id();
            $actor = $actorId ? User::query()->find($actorId) : null;
            if (! $actor) {
                throw new CommerceSyncException(__('commerce.orders.errors.sale_actor_missing'), false);
            }
            if (! $actor->is_super_admin && ! $actor->canAccessStore((int) $locked->store_id)) {
                throw new CommerceSyncException(__('commerce.orders.errors.sale_store_access_denied'), false);
            }
            $paymentMethodId = (int) data_get($locked->order->connection->settings, 'payment_method_id', 0);
            $paymentMethod = PaymentMethod::query()->active()->find($paymentMethodId);
            if (! $paymentMethod) {
                throw new CommerceSyncException(__('commerce.orders.errors.payment_method_missing'), false);
            }

            $plans = [];
            foreach ($locked->items as $allocationItem) {
                $orderItem = $allocationItem->orderItem;
                if (! $orderItem?->product_id) {
                    throw new CommerceSyncException(__('commerce.sync.errors.fulfillment_line_missing'), false);
                }

                $quantity = bcsub(
                    (string) $allocationItem->quantity,
                    (string) $allocationItem->quantity_refunded,
                    4,
                );
                if (bccomp($quantity, '0', 4) <= 0) {
                    continue;
                }
                $product = Product::query()->withTrashed()->with('unit')->find($orderItem->product_id);
                $variant = $orderItem->variant_id
                    ? ProductVariant::query()->withTrashed()->find($orderItem->variant_id)
                    : null;
                if (! $product || ($orderItem->variant_id && ! $variant)) {
                    throw new CommerceSyncException(__('commerce.sync.errors.fulfillment_line_missing'), false);
                }
                $level = StockLevel::query()
                    ->where('store_id', $locked->store_id)
                    ->where('product_id', $orderItem->product_id)
                    ->where('variant_id', $orderItem->variant_id)
                    ->lockForUpdate()
                    ->first();
                if (! $level || bccomp((string) $level->quantity, $quantity, 4) < 0) {
                    throw new CommerceSyncException(__('commerce.orders.errors.fulfillment_stock_changed', [
                        'sku' => $orderItem->sku_snapshot ?: $orderItem->product_name_snapshot,
                    ]), false);
                }

                $level->reserved_quantity = bccomp((string) $level->reserved_quantity, $quantity, 4) >= 0
                    ? bcsub((string) $level->reserved_quantity, $quantity, 4)
                    : '0';
                $level->save();

                $slices = $this->movementSlices(
                    (int) $locked->store_id,
                    (int) $orderItem->product_id,
                    $orderItem->variant_id ? (int) $orderItem->variant_id : null,
                    $quantity,
                );
                $plans[] = compact('allocationItem', 'orderItem', 'product', 'variant', 'level', 'slices', 'quantity');
            }

            $salePlans = array_map(fn (array $plan) => [
                'allocation_item' => $plan['allocationItem'],
                'order_item' => $plan['orderItem'],
                'product' => $plan['product'],
                'variant' => $plan['variant'],
                'level' => $plan['level'],
                'slices' => $plan['slices'],
                'quantity' => $plan['quantity'],
            ], $plans);
            $sale = $this->createSale->execute($locked, $salePlans, $paymentMethod, (int) $actor->getKey());

            foreach ($plans as $plan) {
                $allocationItem = $plan['allocationItem'];
                $orderItem = $plan['orderItem'];
                $quantity = $plan['quantity'];
                foreach ($plan['slices'] as $slice) {
                    ($this->recordMovement)(
                        (int) $locked->store_id,
                        (int) $orderItem->product_id,
                        $orderItem->variant_id ? (int) $orderItem->variant_id : null,
                        $slice['batch_id'],
                        bcmul($slice['quantity'], '-1', 4),
                        'sale',
                        Sale::class,
                        (int) $sale->getKey(),
                        notes: __('commerce.orders.sale_stock_movement_note', [
                            'sale' => $sale->number,
                        ]),
                        createdBy: (int) $actor->getKey(),
                    );
                }

                $allocationItem->forceFill(['quantity_fulfilled' => $quantity])->save();
                $fulfilled = bcadd((string) $orderItem->quantity_fulfilled, $quantity, 4);
                $orderItem->forceFill([
                    'quantity_fulfilled' => bccomp($fulfilled, (string) $orderItem->quantity, 4) > 0
                        ? (string) $orderItem->quantity
                        : $fulfilled,
                ])->save();
            }

            $locked->forceFill([
                'status' => ChannelOrderAllocation::STATUS_FULFILLED,
                'fulfilled_at' => now('UTC'),
            ])->save();

            $hasOpenAllocations = $locked->order->allocations()
                ->whereKeyNot($locked->getKey())
                ->where('status', '!=', ChannelOrderAllocation::STATUS_FULFILLED)
                ->exists();
            $locked->order->forceFill([
                'status' => $hasOpenAllocations ? ChannelOrder::STATUS_PROCESSING : ChannelOrder::STATUS_COMPLETED,
                'fulfillment_status' => $hasOpenAllocations ? 'partial' : 'fulfilled',
                'last_error' => null,
            ])->save();

            $this->outbox->execute(
                'fulfillment.sync',
                $locked,
                ['allocation_id' => $locked->getKey()],
                dedupeSource: 'allocation-'.$locked->getKey(),
                connectionId: (int) $locked->order->commerce_connection_id,
            );

            do_action('sale.after_complete', $sale);

            return $locked->fresh(['order', 'sale', 'items.orderItem']);
        });
    }

    /** @return list<array{batch_id:?int,quantity:string}> */
    private function movementSlices(int $storeId, int $productId, ?int $variantId, string $quantity): array
    {
        if (! Product::query()->whereKey($productId)->value('track_batches')) {
            return [['batch_id' => null, 'quantity' => $quantity]];
        }

        $remaining = $quantity;
        $slices = [];
        $batches = ProductBatch::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->whereRaw('quantity > 0')
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            $take = bccomp((string) $batch->quantity, $remaining, 4) >= 0
                ? $remaining
                : (string) $batch->quantity;
            $slices[] = ['batch_id' => (int) $batch->getKey(), 'quantity' => $take];
            $remaining = bcsub($remaining, $take, 4);
        }

        if (bccomp($remaining, '0', 4) > 0) {
            throw new CommerceSyncException(__('commerce.orders.errors.fulfillment_batch_stock_changed'), false);
        }

        return $slices;
    }
}
