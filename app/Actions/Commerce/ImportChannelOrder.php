<?php

namespace App\Actions\Commerce;

use App\Models\ChannelOrder;
use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Commerce\Data\RemoteOrder;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use Illuminate\Support\Facades\DB;

final readonly class ImportChannelOrder
{
    public function __construct(
        private AllocateChannelOrder $allocator,
        private ReleaseChannelOrderReservations $releaseReservations,
        private ApplyChannelOrderRefunds $refunds,
    ) {}

    public function execute(CommerceConnection $connection, RemoteOrder $remote): ChannelOrder
    {
        if (! $remote->paid && ! $remote->cancelled) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.order_not_paid'));
        }
        if (! $remote->cancelled && $remote->lines === []) {
            throw new CommerceSyncException(__('commerce.orders.errors.order_has_no_items'), false);
        }

        $existing = ChannelOrder::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->where('remote_order_id', $remote->remoteId)
            ->with('items')
            ->first();
        $allocationChanged = $this->allocationFingerprintFromLocal($existing) !== $this->allocationFingerprintFromRemote($remote);

        $order = DB::transaction(function () use ($connection, $remote): ChannelOrder {
            $order = ChannelOrder::query()->updateOrCreate(
                [
                    'commerce_connection_id' => $connection->getKey(),
                    'remote_order_id' => $remote->remoteId,
                ],
                [
                    'remote_order_number' => $remote->number,
                    'status' => $remote->cancelled ? ChannelOrder::STATUS_CANCELLED : ChannelOrder::STATUS_PENDING,
                    'financial_status' => $remote->financialStatus,
                    'fulfillment_status' => $remote->fulfillmentStatus,
                    'currency_code' => $remote->currencyCode,
                    'subtotal' => $remote->subtotal,
                    'discount_total' => $remote->discountTotal,
                    'tax_total' => $remote->taxTotal,
                    'shipping_total' => $remote->shippingTotal,
                    'grand_total' => $remote->grandTotal,
                    'refunded_total' => $remote->refundedTotal,
                    'customer_id' => $this->customerId($connection, $remote),
                    'remote_customer_id' => $remote->remoteCustomerId,
                    'payment_reference' => $remote->paymentReference,
                    'billing_address' => $remote->billingAddress,
                    'shipping_address' => $remote->shippingAddress,
                    'payload' => $remote->payload,
                    'placed_at' => $remote->placedAt,
                    'paid_at' => $remote->paidAt,
                    'cancelled_at' => $remote->cancelledAt,
                    'remote_updated_at' => $remote->remoteUpdatedAt,
                    'imported_at' => now('UTC'),
                    'last_error' => null,
                ],
            );

            $remoteLineIds = [];
            foreach ($remote->lines as $line) {
                $remoteLineIds[] = $line->remoteId;
                [$productId, $variantId] = $this->localProduct($connection, $line->remoteProductId, $line->remoteVariantId, $line->sku);
                $order->items()->updateOrCreate(
                    ['remote_line_id' => $line->remoteId],
                    [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'product_name_snapshot' => $line->name,
                        'sku_snapshot' => $line->sku,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unitPrice,
                        'discount_amount' => $line->discountAmount,
                        'tax_amount' => $line->taxAmount,
                        'line_total' => $line->lineTotal,
                        'quantity_fulfilled' => $line->quantityFulfilled,
                        'quantity_refunded' => $line->quantityRefunded,
                        'metadata' => $line->metadata,
                    ],
                );
            }
            $order->items()->whereNotIn('remote_line_id', $remoteLineIds)->delete();

            return $order->fresh('items');
        });

        $this->refunds->execute($order);

        if ($remote->cancelled) {
            $this->releaseReservations->execute($order);
        } elseif ($allocationChanged
            && $order->allocations()->whereIn('status', ['pending', 'reserved'])->exists()
            && $order->allocations()->where('status', 'fulfilled')->doesntExist()) {
            $this->releaseReservations->execute($order);
            $order->allocations()->where('status', 'cancelled')->delete();
            $order->forceFill(['status' => ChannelOrder::STATUS_PENDING])->save();
            $this->allocate($order);
        } elseif ($order->allocations()->doesntExist()) {
            $this->allocate($order);
        } else {
            $hasFulfilled = $order->allocations()->where('status', 'fulfilled')->exists();
            $hasOpen = $order->allocations()->whereIn('status', ['pending', 'reserved'])->exists();
            $order->forceFill([
                'status' => $hasOpen
                    ? ($hasFulfilled ? ChannelOrder::STATUS_PROCESSING : ChannelOrder::STATUS_ALLOCATED)
                    : ChannelOrder::STATUS_COMPLETED,
                'fulfillment_status' => $hasOpen
                    ? ($hasFulfilled ? 'partial' : 'unfulfilled')
                    : 'fulfilled',
                'last_error' => null,
            ])->save();
        }

        return $order;
    }

    private function allocate(ChannelOrder $order): void
    {
        try {
            $this->allocator->execute($order);
        } catch (\Throwable $e) {
            $order->forceFill([
                'status' => ChannelOrder::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 65535),
            ])->save();
            throw $e;
        }
    }

    private function allocationFingerprintFromLocal(?ChannelOrder $order): ?string
    {
        if (! $order) {
            return null;
        }

        return hash('sha256', json_encode($order->items
            ->map(fn ($item) => [
                $item->remote_line_id,
                $item->sku_snapshot,
                (string) $item->quantity,
                (string) $item->quantity_fulfilled,
                (string) $item->quantity_refunded,
            ])
            ->sortBy(0)
            ->values()
            ->all(), JSON_THROW_ON_ERROR));
    }

    private function allocationFingerprintFromRemote(RemoteOrder $order): string
    {
        return hash('sha256', json_encode(collect($order->lines)
            ->map(fn ($line) => [
                $line->remoteId,
                $line->sku,
                (string) $line->quantity,
                (string) $line->quantityFulfilled,
                (string) $line->quantityRefunded,
            ])
            ->sortBy(0)
            ->values()
            ->all(), JSON_THROW_ON_ERROR));
    }

    private function customerId(CommerceConnection $connection, RemoteOrder $order): ?int
    {
        if ($order->remoteCustomerId) {
            $mapping = CommerceEntityMapping::query()
                ->where('commerce_connection_id', $connection->getKey())
                ->where('entity_type', CommerceEntityMapping::TYPE_CUSTOMER)
                ->where('remote_id', $order->remoteCustomerId)
                ->first();
            if ($mapping?->local_type === Customer::class) {
                return (int) $mapping->local_id;
            }
        }

        $email = $order->customer['email'] ?? $order->billingAddress['email'] ?? null;
        $phone = $order->customer['phone'] ?? $order->billingAddress['phone'] ?? null;

        return Customer::query()
            ->when(filled($email), fn ($query) => $query->where('email', $email))
            ->when(! filled($email) && filled($phone), fn ($query) => $query->where('phone', $phone))
            ->value('id');
    }

    /** @return array{0:?int,1:?int} */
    private function localProduct(CommerceConnection $connection, ?string $remoteProductId, ?string $remoteVariantId, ?string $sku): array
    {
        foreach (array_filter([$remoteVariantId, $remoteProductId]) as $remoteId) {
            $mapping = CommerceEntityMapping::query()
                ->where('commerce_connection_id', $connection->getKey())
                ->whereIn('entity_type', [CommerceEntityMapping::TYPE_VARIANT, CommerceEntityMapping::TYPE_PRODUCT])
                ->where('remote_id', $remoteId)
                ->first();
            if ($mapping?->local_type === ProductVariant::class) {
                $variant = ProductVariant::query()->find($mapping->local_id);

                return [$variant?->product_id, $variant?->getKey()];
            }
            if ($mapping?->local_type === Product::class) {
                return [(int) $mapping->local_id, null];
            }
        }

        if (filled($sku)) {
            $variant = ProductVariant::query()->where('sku', $sku)->first();
            if ($variant) {
                return [$variant->product_id, $variant->getKey()];
            }
            $productId = Product::query()->where('sku', $sku)->value('id');
            if ($productId) {
                return [(int) $productId, null];
            }
        }

        return [null, null];
    }
}
