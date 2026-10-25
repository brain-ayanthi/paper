<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Returns the effective `cost_price` / `selling_price` / `mrp` for a
 * product (or one of its variants) at a given store, honoring per-store
 * overrides.
 *
 * Resolution order, per column, most-specific wins:
 *
 *   Variant line (a `$variant` is passed):
 *     1. store×variant override — product_store_prices (store_id, variant_id)
 *     2. variant base price     — product_variants.cost/selling
 *     3. product base price     — products.cost/selling (cost fallback only;
 *        variant products keep selling on the variant)
 *
 *   Product line (no `$variant`):
 *     1. store override — product_prices (store_id, variant_id IS NULL)
 *     2. product base   — products.cost/selling/mrp
 *
 * Columns resolve independently — a store can override just selling
 * price (a local promo) while inheriting cost + mrp.
 *
 * Returns an associative array with keys `cost_price`, `selling_price`,
 * `mrp`. The MRP value may be null.
 *
 * Usage (sales / checkout):
 *
 *   $prices = (new ResolveProductPrice)($product, $storeId, $variant);
 *   $line->unit_price = $prices['selling_price'];
 *
 * Hook points:
 *   - filter `product.prices.resolved` → adjust the final array
 *     (loyalty discounts, time-based promos, etc. layer on here).
 */
class ResolveProductPrice
{
    /**
     * @return array{cost_price: string, selling_price: string, mrp: ?string}
     */
    public function __invoke(Product $product, int|string|null $storeId = null, ?ProductVariant $variant = null): array
    {
        // Base layer — variant prices take precedence over the parent's,
        // falling through to the product for any column the variant
        // leaves null.
        if ($variant) {
            $mrp = $variant->mrp ?? $product->mrp;
            $effective = [
                'cost_price'    => (string) ($variant->cost_price    ?? $product->cost_price),
                'selling_price' => (string) ($variant->selling_price ?? $product->selling_price),
                'mrp'           => $mrp !== null ? (string) $mrp : null,
            ];
        } else {
            $effective = [
                'cost_price'    => (string) $product->cost_price,
                'selling_price' => (string) $product->selling_price,
                'mrp'           => $product->mrp !== null ? (string) $product->mrp : null,
            ];
        }

        if ($storeId) {
            $query = DB::table('product_store_prices')
                ->where('product_id', $product->id)
                ->where('store_id', $storeId);

            $query = $variant
                ? $query->where('variant_id', $variant->id)
                : $query->whereNull('variant_id');

            $override = $query->first(['cost_price', 'selling_price', 'mrp']);

            if ($override) {
                foreach (['cost_price', 'selling_price', 'mrp'] as $col) {
                    if ($override->{$col} !== null) {
                        $effective[$col] = (string) $override->{$col};
                    }
                }
            }
        }

        return apply_filters('product.prices.resolved', $effective, $product, $storeId, $variant);
    }
}
