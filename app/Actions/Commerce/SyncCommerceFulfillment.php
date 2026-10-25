<?php

namespace App\Actions\Commerce;

use App\Models\ChannelOrderAllocation;
use App\Models\CommerceConnection;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;

final readonly class SyncCommerceFulfillment
{
    public function __construct(private CommerceProviderRegistry $providers) {}

    public function execute(CommerceConnection $connection, int $allocationId): void
    {
        $allocation = ChannelOrderAllocation::query()
            ->with(['order.allocations:id,channel_order_id,status', 'items.orderItem', 'store'])
            ->find($allocationId);
        if (! $allocation || (int) $allocation->order->commerce_connection_id !== (int) $connection->getKey()) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.fulfillment_missing'));
        }
        if ($allocation->status !== ChannelOrderAllocation::STATUS_FULFILLED) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.fulfillment_not_ready'));
        }
        if (filled($allocation->remote_fulfillment_id)) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.fulfillment_already_synced'));
        }

        $mapping = $connection->storeMappings()
            ->where('store_id', $allocation->store_id)
            ->where('is_active', true)
            ->where('fulfill_orders', true)
            ->first();
        if (! $mapping) {
            throw new CommerceSyncException(__('commerce.sync.errors.fulfillment_store_mapping_missing'), false);
        }

        $lines = $allocation->items->map(function ($allocationItem): array {
            if (! $allocationItem->orderItem) {
                throw new CommerceSyncException(__('commerce.sync.errors.fulfillment_line_missing'), false);
            }

            return [
                'remote_line_id' => (string) $allocationItem->orderItem->remote_line_id,
                'quantity' => (int) $allocationItem->quantity,
            ];
        })->values()->all();

        $remoteId = $this->providers->get($connection->provider)->fulfillOrder($connection, [
            'remote_order_id' => (string) $allocation->order->remote_order_id,
            'remote_location_id' => $mapping->remote_location_id,
            'all_fulfilled' => $allocation->order->allocations->every(
                fn ($other) => $other->status === ChannelOrderAllocation::STATUS_FULFILLED,
            ),
            'lines' => $lines,
        ]);

        if (filled($remoteId)) {
            $allocation->forceFill(['remote_fulfillment_id' => $remoteId])->save();
        }
    }
}
