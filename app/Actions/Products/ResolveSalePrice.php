<?php

namespace App\Actions\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/** Resolve the authoritative selling price under the configured pricing policy. */
class ResolveSalePrice
{
    public function __construct(private readonly ResolveProductPrice $resolveProductPrice) {}

    public function __invoke(
        Product $product,
        int $storeId,
        ?ProductVariant $variant = null,
        ?int $batchId = null,
    ): string {
        if ((Company::current()?->usesBatchPricing() ?? false) && $batchId !== null) {
            $batchPrice = ProductBatch::query()
                ->whereKey($batchId)
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->when($variant, fn ($q) => $q->where('variant_id', $variant->id))
                ->when(! $variant, fn ($q) => $q->whereNull('variant_id'))
                ->value('selling_price');

            if ($batchPrice !== null && bccomp((string) $batchPrice, '0', 4) > 0) {
                return (string) $batchPrice;
            }

            $fallback = (string) ($this->resolveProductPrice)($product, $storeId, $variant)['selling_price'];
            if (bccomp($fallback, '0', 4) > 0) {
                return $fallback;
            }

            throw ValidationException::withMessages([
                'items' => [__('products.validation.batch_price_unavailable', ['product' => $product->name])],
            ]);
        }

        return (string) ($this->resolveProductPrice)($product, $storeId, $variant)['selling_price'];
    }
}
