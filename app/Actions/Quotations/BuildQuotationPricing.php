<?php

namespace App\Actions\Quotations;

use App\Actions\Products\ResolveProductPrice;
use App\Actions\Sales\PriceCart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Customers\CustomerDiscountResolver;
use App\Services\Tax\TaxResolver;
use RuntimeException;

class BuildQuotationPricing
{
    public function __construct(
        private readonly ResolveProductPrice $resolvePrice,
        private readonly PriceCart $priceCart,
        private readonly CustomerDiscountResolver $customerDiscount,
        private readonly TaxResolver $taxResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $inputLines
     * @return array{items: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function __invoke(array $inputLines, Store $store, ?int $customerId, ?User $actor): array
    {
        $productIds = collect($inputLines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $variantIds = collect($inputLines)->pluck('variant_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        $products = Product::query()
            ->with(['unit:id,code', 'taxGroup.components', 'category.taxGroup.components'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $customerPct = $this->customerDiscount->percentForId($customerId);
        $hasCustomerDiscount = bccomp($customerPct, '0', 4) > 0;
        $resolved = [];

        foreach (array_values($inputLines) as $index => $raw) {
            if (bccomp((string) ($raw['discount_percent'] ?? '0'), '0', 4) > 0
                && ! ($actor?->hasPermission('sales.discount', (int) $store->id) ?? false)) {
                throw new RuntimeException(__('quotations.errors.discount_not_allowed'));
            }

            $product = $products->get((int) ($raw['product_id'] ?? 0));
            if (! $product || ! $product->is_active) {
                throw new RuntimeException(__('quotations.errors.product_unavailable'));
            }

            $variantId = filled($raw['variant_id'] ?? null) ? (int) $raw['variant_id'] : null;
            $variant = $variantId ? $variants->get($variantId) : null;
            if ($variantId && (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active)) {
                throw new RuntimeException(__('quotations.errors.variant_unavailable'));
            }

            $price = ($this->resolvePrice)($product, (int) $store->id, $variant);
            $line = [
                'product_id' => (int) $product->id,
                'variant_id' => $variantId,
                'quantity' => (string) ($raw['quantity'] ?? '0'),
                'unit_price' => (string) ($price['selling_price'] ?? '0'),
                'discount_percent' => (string) ($raw['discount_percent'] ?? '0'),
                'notes' => filled($raw['notes'] ?? null) ? trim((string) $raw['notes']) : null,
            ];

            if ($hasCustomerDiscount) {
                $gross = bcmul($line['quantity'], $line['unit_price'], 8);
                $own = bcdiv(bcmul($gross, $line['discount_percent'], 8), '100', 8);
                $remaining = bcsub($gross, $own, 8);
                $customer = bcdiv(bcmul($remaining, $customerPct, 8), '100', 8);
                $line['discount_amount'] = bcadd($own, $customer, 4);
            }

            $resolved[] = ['product' => $product, 'variant' => $variant, 'raw' => $line, 'index' => $index];
        }

        $priced = $this->priceCart->handle($resolved, $store, $actor);
        $items = [];
        foreach ($priced->lines as $pricedLine) {
            /** @var Product $product */
            $product = $pricedLine['product'];
            /** @var ProductVariant|null $variant */
            $variant = $resolved[$pricedLine['index']]['variant'];
            $raw = $pricedLine['raw'];

            $items[] = [
                'product_id' => (int) $product->id,
                'variant_id' => $variant?->id,
                'product_name_snapshot' => (string) $product->name,
                'variant_name_snapshot' => $variant?->label,
                'sku_snapshot' => $variant?->sku ?: $product->sku,
                'barcode_snapshot' => $variant?->barcode ?: $product->barcode,
                'hsn_snapshot' => $product->hsn_code,
                'quantity' => (string) $raw['quantity'],
                'unit' => (string) ($product->unit?->code ?? 'pc'),
                'unit_price' => (string) $raw['unit_price'],
                'discount_percent' => (string) ($raw['discount_percent'] ?? '0'),
                'discount_amount' => (string) $pricedLine['discount'],
                'tax_group_id' => $this->taxResolver->effectiveTaxGroupId($product),
                'tax_breakdown' => $pricedLine['breakdown']->jsonSerialize(),
                'tax_amount' => (string) $pricedLine['tax'],
                'line_subtotal' => (string) $pricedLine['net'],
                'line_total' => (string) $pricedLine['line_total'],
                'notes' => $raw['notes'] ?? null,
                'sort_order' => count($items) + 1,
            ];
        }

        return [
            'items' => $items,
            'totals' => [
                'subtotal' => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total' => $priced->taxTotal,
                'grand_total' => $priced->grandTotal,
            ],
        ];
    }
}
