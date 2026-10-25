<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockLevel;

/**
 * What one unit of a sale line COST us, resolved at the moment of sale.
 *
 * Every profit figure in the product depends on this: `sale_items.unit_cost_snapshot`
 * feeds gross profit and margin on the Sales-by-Product and Sales-by-Category
 * reports, COGS on the dashboard and `daily_metrics`, and the refund value of a
 * returned line. Until this existed `CompleteSale` wrote a literal `'0'`, so
 * every one of those read back as "cost 0, margin 100%".
 *
 * Resolution order — most specific source first:
 *
 *   1. **The batch** it was picked from (`product_batches.cost_price`). If we
 *      know which physical lot went out the door, that lot's landed cost is
 *      the true one; two batches of the same product genuinely cost different
 *      amounts.
 *   2. **The store's weighted average cost** (`stock_levels.weighted_average_cost`),
 *      maintained by `RecordStockMovement` on every receipt. This is the right
 *      answer for anything non-batched that has ever been purchased.
 *   3. **The variant's, then the product's, catalogue `cost_price`** — the
 *      owner-entered figure. Covers stock that was never received through a
 *      purchase (opening stock typed into the product form).
 *   4. **Components, for a kit.** A kit has no stock of its own, so its cost is
 *      the sum of its components' costs at the same store — resolved through
 *      this same ladder, one level deep.
 *
 * Returns a 4dp decimal string, `'0.0000'` when genuinely unknown (a service
 * with no cost entered anywhere). Zero here is a real answer, not a bug — but
 * it now only happens when the data really has no cost in it.
 */
class ResolveLineCost
{
    /**
     * @param  int|null  $batchId   the batch this line was picked from, if any
     * @param  StockLevel|null  $level  the already-locked stock row, if the
     *         caller has one — saves a second query inside a hot transaction
     */
    public function __invoke(
        Product $product,
        int $storeId,
        ?int $variantId = null,
        ?int $batchId = null,
        ?StockLevel $level = null,
    ): string {
        if ($batchId !== null) {
            $batchCost = ProductBatch::query()->whereKey($batchId)->value('cost_price');
            if ($this->isPositive($batchCost)) {
                return $this->fmt($batchCost);
            }
        }

        $wac = $level
            ? $level->weighted_average_cost
            : StockLevel::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->value('weighted_average_cost');

        if ($this->isPositive($wac)) {
            return $this->fmt($wac);
        }

        if ($variantId !== null) {
            $variantCost = ProductVariant::query()->whereKey($variantId)->value('cost_price');
            if ($this->isPositive($variantCost)) {
                return $this->fmt($variantCost);
            }
        }

        if ($this->isPositive($product->cost_price)) {
            return $this->fmt($product->cost_price);
        }

        // A kit is assembled at the till, so nothing was ever purchased under
        // its own SKU — its cost only exists as the sum of what's inside it.
        if ($product->isKitType()) {
            return $this->kitCost($product, $storeId);
        }

        return '0.0000';
    }

    /**
     * Sum the components' costs × their quantities. One level deep: a kit
     * inside a kit would recurse here, and v1.0 doesn't build those.
     */
    private function kitCost(Product $kit, int $storeId): string
    {
        $kit->loadMissing('kitItems.component');

        $total = '0';
        foreach ($kit->kitItems as $item) {
            $component = $item->component;
            if (! $component || $component->id === $kit->id) {
                continue;
            }

            $unit = $this(
                product:   $component,
                storeId:   $storeId,
                variantId: $item->component_variant_id ? (int) $item->component_variant_id : null,
            );

            $total = bcadd($total, bcmul($unit, (string) $item->quantity, 4), 4);
        }

        return $this->fmt($total);
    }

    /** A null / zero / negative cost is "no answer here", so try the next source. */
    private function isPositive(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return bccomp((string) $value, '0', 4) > 0;
    }

    private function fmt(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
}
