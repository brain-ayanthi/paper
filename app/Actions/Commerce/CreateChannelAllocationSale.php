<?php

namespace App\Actions\Commerce;

use App\Actions\Products\ResolveLineCost;
use App\Actions\Sales\GenerateSaleNumber;
use App\Models\ChannelOrderAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\Commerce\AllocateAmountAcrossQuantities;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use Illuminate\Support\Str;

/** Posts a provider-priced store allocation into the canonical POS sales ledger. */
final readonly class CreateChannelAllocationSale
{
    public function __construct(
        private GenerateSaleNumber $generateSaleNumber,
        private ResolveLineCost $resolveLineCost,
        private AllocateAmountAcrossQuantities $allocateAmount,
    ) {}

    /**
     * @param  list<array{allocation_item:mixed,order_item:mixed,product:mixed,variant:mixed,level:mixed,slices:list<array{batch_id:?int,quantity:string}>,quantity:string}>  $plans
     */
    public function execute(
        ChannelOrderAllocation $allocation,
        array $plans,
        PaymentMethod $paymentMethod,
        int $userId,
    ): Sale {
        if ($allocation->sale_id) {
            return Sale::query()->with(['items', 'payments'])->findOrFail($allocation->sale_id);
        }

        $order = $allocation->order;
        $occurredAt = $order->paid_at ?? $order->placed_at ?? now();
        $number = ($this->generateSaleNumber)((int) $allocation->store_id, $occurredAt);
        $netSubtotal = bcsub(
            bcsub((string) $allocation->grand_total, (string) $allocation->tax_total, 4),
            (string) $allocation->shipping_total,
            4,
        );

        if (bccomp($netSubtotal, '0', 4) < 0) {
            throw new CommerceSyncException(__('commerce.orders.errors.sale_totals_invalid'), false);
        }

        $sale = new Sale;
        $sale->fill([
            'store_id' => (int) $allocation->store_id,
            'cashier_id' => $userId,
            'customer_id' => $order->customer_id ? (int) $order->customer_id : null,
            'local_uuid' => (string) Str::uuid(),
            'sale_date' => $occurredAt->toDateString(),
            'sale_datetime' => $occurredAt,
            'currency_code' => (string) $order->currency_code,
            'exchange_rate_to_base' => '1',
            'notes' => __('commerce.orders.sale_note', [
                'channel' => $order->connection->name,
                'order' => $order->remote_order_number ?: $order->remote_order_id,
            ]),
            'origin' => 'api',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $sale->forceFill([
            'number' => $number,
            'status' => Sale::STATUS_COMPLETED,
            'subtotal' => $netSubtotal,
            'discount_total' => (string) $allocation->discount_total,
            'tax_total' => (string) $allocation->tax_total,
            'additional_charges_total' => (string) $allocation->shipping_total,
            'rounding_adjustment' => '0',
            'grand_total' => (string) $allocation->grand_total,
            'paid_total' => (string) $allocation->grand_total,
            'change_returned' => '0',
            'balance_due' => '0',
        ])->save();

        $sortOrder = 0;
        foreach ($plans as $plan) {
            $allocationItem = $plan['allocation_item'];
            $orderItem = $plan['order_item'];
            $product = $plan['product'];
            $variant = $plan['variant'];
            $quantity = $plan['quantity'];
            $ratio = bcdiv($quantity, (string) $orderItem->quantity, 8);
            $lineGross = bcmul((string) $orderItem->unit_price, $quantity, 4);
            $lineDiscount = bcmul((string) $orderItem->discount_amount, $ratio, 4);
            $lineTax = bcmul((string) $orderItem->tax_amount, $ratio, 4);
            $lineNet = bcmul((string) $orderItem->line_total, $ratio, 4);

            $sliceQuantities = array_column($plan['slices'], 'quantity');
            $grossBySlice = $this->allocateAmount->execute($lineGross, $quantity, $sliceQuantities);
            $discountBySlice = $this->allocateAmount->execute($lineDiscount, $quantity, $sliceQuantities);
            $taxBySlice = $this->allocateAmount->execute($lineTax, $quantity, $sliceQuantities);
            $netBySlice = $this->allocateAmount->execute($lineNet, $quantity, $sliceQuantities);

            foreach ($plan['slices'] as $index => $slice) {
                $sliceGross = $grossBySlice[$index];
                $sliceDiscount = $discountBySlice[$index];
                $sliceTax = $taxBySlice[$index];

                $sliceNet = $netBySlice[$index];
                $discountPercent = bccomp($sliceGross, '0', 4) > 0
                    ? bcmul(bcdiv($sliceDiscount, $sliceGross, 8), '100', 4)
                    : '0';

                SaleItem::create([
                    'sale_id' => $sale->getKey(),
                    'product_id' => $product->getKey(),
                    'variant_id' => $variant?->getKey(),
                    'batch_id' => $slice['batch_id'],
                    'product_name_snapshot' => (string) $orderItem->product_name_snapshot,
                    'sku_snapshot' => $orderItem->sku_snapshot ?: ($variant?->sku ?: $product->sku),
                    'barcode_snapshot' => $variant?->barcode ?: $product->barcode,
                    'hsn_snapshot' => $product->hsn_code,
                    'quantity' => (string) $slice['quantity'],
                    'unit' => (string) ($product->unit?->code ?: 'pc'),
                    'unit_price' => (string) $orderItem->unit_price,
                    'unit_cost_snapshot' => ($this->resolveLineCost)(
                        product: $product,
                        storeId: (int) $allocation->store_id,
                        variantId: $variant?->getKey(),
                        batchId: $slice['batch_id'],
                        level: $plan['level'],
                    ),
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $sliceDiscount,
                    'tax_group_id' => null,
                    'tax_breakdown' => [
                        'total' => $sliceTax,
                        'rate_total' => '0.0000',
                        'is_inclusive' => false,
                        'classification' => 'channel',
                        'components' => [],
                    ],
                    'tax_amount' => $sliceTax,
                    'line_subtotal' => $sliceNet,
                    'line_total' => bcadd($sliceNet, $sliceTax, 4),
                    'notes' => __('commerce.orders.sale_item_note'),
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        SalePayment::create([
            'sale_id' => $sale->getKey(),
            'payment_method_id' => $paymentMethod->getKey(),
            'amount' => (string) $allocation->grand_total,
            'reference' => $order->payment_reference ?: ($order->remote_order_number ?: $order->remote_order_id),
            'currency_code' => (string) $order->currency_code,
            'paid_at' => $order->paid_at ?? $occurredAt,
            'created_by' => $userId,
            'notes' => __('commerce.orders.sale_payment_note', ['channel' => $order->connection->name]),
        ]);

        $allocation->forceFill(['sale_id' => $sale->getKey()])->save();

        return $sale->load(['items', 'payments']);
    }
}
