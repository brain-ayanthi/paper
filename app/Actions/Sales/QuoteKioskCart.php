<?php

namespace App\Actions\Sales;

use App\Actions\Inventory\ResolveFefoBatch;
use App\Actions\Products\ResolveLineCost;
use App\Actions\Products\ResolveSalePrice;
use App\Exceptions\InsufficientStock;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Customers\CustomerDiscountResolver;
use App\Services\Inventory\KioskBatchReservations;
use App\Support\PricedCart;
use RuntimeException;

/** Resolve a kiosk cart's quantity-qualified batches and authoritative total. */
class QuoteKioskCart
{
    public function __construct(
        private readonly PriceCart $priceCart,
        private readonly CustomerDiscountResolver $customerDiscount,
        private readonly ResolveSalePrice $resolvePrice,
        private readonly ResolveLineCost $resolveLineCost,
        private readonly ResolveFefoBatch $resolveFefoBatch,
        private readonly KioskBatchReservations $kioskReservations,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{store: Store, priced: PricedCart}
     */
    public function __invoke(array $header, array $lines, bool $lockBatches = false): array
    {
        if ($lines === []) {
            throw new RuntimeException(__('sales.errors.empty_cart'));
        }

        $storeId = (int) $header['store_id'];
        $store = Store::query()->where('is_active', true)->findOrFail($storeId);
        $customerPct = $this->customerDiscount->percentForId(
            isset($header['customer_id']) && $header['customer_id'] !== ''
                ? (int) $header['customer_id']
                : null,
        );

        $resolvedLines = [];
        foreach (array_values($lines) as $i => $raw) {
            $product = Product::query()
                ->with(['unit:id,code', 'taxGroup.components', 'category.taxGroup.components'])
                ->findOrFail((int) $raw['product_id']);

            $variant = null;
            if (isset($raw['variant_id']) && $raw['variant_id'] !== '') {
                $variant = ProductVariant::query()->findOrFail((int) $raw['variant_id']);
            }

            $qty = (string) ($raw['quantity'] ?? '0');
            if (bccomp($qty, '0', 4) <= 0) {
                throw new RuntimeException(__('validation.gt.numeric', [
                    'attribute' => "items.{$i}.quantity",
                    'value' => 0,
                ]));
            }

            if ($product->track_stock) {
                $level = StockLevel::query()
                    ->where('store_id', $storeId)
                    ->where('product_id', $product->id)
                    ->where('variant_id', $variant?->id)
                    ->first();
                $available = $level
                    ? bcsub((string) $level->quantity, (string) $level->reserved_quantity, 4)
                    : '0.0000';
                $available = bcsub($available, $this->kioskReservations->forStock(
                    $storeId,
                    (int) $product->id,
                    $variant?->id ? (int) $variant->id : null,
                ), 4);
                if (bccomp($available, '0', 4) < 0) {
                    $available = '0.0000';
                }
                if (bccomp($qty, $available, 4) > 0) {
                    throw new InsufficientStock((string) $product->name, $available, $qty);
                }
            }

            $batch = null;
            if ($product->track_batches) {
                $batch = ($this->resolveFefoBatch)(
                    $storeId,
                    (int) $product->id,
                    $variant?->id ? (int) $variant->id : null,
                    (bool) (Company::current()?->block_expired_batch_sale ?? false),
                    $qty,
                    $lockBatches,
                );
                if ($batch === null) {
                    throw new RuntimeException(__('sales.errors.invalid_batch', ['name' => $product->name]));
                }
            }

            $sellingPrice = ($this->resolvePrice)($product, $storeId, $variant, $batch?->id);
            $lineDiscount = null;
            if (bccomp($customerPct, '0', 4) > 0) {
                $gross = bcmul($qty, $sellingPrice, 8);
                $lineDiscount = bcadd(bcdiv(bcmul($gross, $customerPct, 8), '100', 8), '0', 4);
            }

            $resolvedLines[] = [
                'raw' => [
                    'product_id' => (int) $product->id,
                    'variant_id' => $variant?->id,
                    'batch_id' => $batch?->id,
                    'quantity' => $qty,
                    'unit_price' => $sellingPrice,
                    'discount_amount' => $lineDiscount,
                    'notes' => isset($raw['notes']) && $raw['notes'] !== '' ? (string) $raw['notes'] : null,
                ],
                'product' => $product,
                'index' => $i,
                'unit_cost' => ($this->resolveLineCost)(
                    product: $product,
                    storeId: $storeId,
                    variantId: $variant?->id ? (int) $variant->id : null,
                    batchId: $batch?->id ? (int) $batch->id : null,
                ),
            ];
        }

        return ['store' => $store, 'priced' => $this->priceCart->handle($resolvedLines, $store)];
    }
}
