<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductLocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Set — or clear — the pick-face shelf location on many products at once,
 * from the products list.
 *
 * This is what makes shelf locations usable at all. A pharmacy with 2,000
 * SKUs is never going to open each product and type a rack number; they
 * filter to "Syrups", type R-12 once, and apply it to all 340.
 *
 * Two scopes, both driven from the list the user is looking at:
 *
 *   - **Selected rows** — explicit ids ticked on the current page.
 *   - **The whole filtered set** — every product matching the active
 *     search + filters, not just the visible page. Server-side paging
 *     means "select all" would otherwise mean "select these 25", which is
 *     useless for the job this exists to do.
 *
 * `set` mode is additive: a blank field is left as it was, so you can push
 * a rack across hundreds of products without disturbing their aisles.
 * `clear` mode is the only destructive path, and it is explicit — it
 * removes the pick-face row for that store and nothing else. Secondary
 * locations (godown, fridge, cabinet) are never touched by either mode;
 * they're per-product decisions.
 *
 * Hook points:
 *   - action `product.locations.bulk_assigned` → ($storeId, $mode, $count)
 */
class BulkAssignProductLocation
{
    /**
     * Upper bound on one run. A filtered set can be the entire catalog,
     * and a shared-hosting request has a wall clock. Hitting this is
     * reported back to the user — never silently truncated — so they can
     * narrow the filter and finish the job.
     */
    public const MAX_PRODUCTS = 10000;

    /** Rows per chunk. Small enough to keep memory flat on cheap hosting. */
    private const CHUNK = 500;

    public function __construct(private UpsertPrimaryProductLocation $upsert) {}

    /**
     * @param  Builder<Product>      $query   The product set to act on, already filtered.
     * @param  array<string, mixed>  $values  aisle/rack/shelf/bin/note — `set` mode only.
     * @param  'set'|'clear'         $mode
     * @return array{affected: int, capped: bool}
     */
    public function __invoke(Builder $query, int $storeId, array $values, string $mode = 'set'): array
    {
        $mode = $mode === 'clear' ? 'clear' : 'set';

        // Nothing to write and not an explicit clear → do nothing rather
        // than walk the catalog to no effect.
        if ($mode === 'set' && $this->isEmpty($values)) {
            return ['affected' => 0, 'capped' => false];
        }

        $affected = 0;
        $capped   = false;

        // chunkById, not chunk: the location write doesn't change the
        // ordering column, but keyset paging is still the only safe way to
        // walk a set this size while writing to it.
        $query->select(['products.id'])->chunkById(self::CHUNK, function ($products) use (&$affected, &$capped, $storeId, $values, $mode) {
            foreach ($products as $product) {
                if ($affected >= self::MAX_PRODUCTS) {
                    $capped = true;

                    return false;
                }

                $written = $mode === 'clear'
                    ? $this->clear($product, $storeId)
                    : ($this->upsert)($product, $storeId, $values) !== null;

                if ($written) {
                    $affected++;
                }
            }

            return true;
        }, 'products.id', 'id');

        do_action('product.locations.bulk_assigned', $storeId, $mode, $affected);

        return ['affected' => $affected, 'capped' => $capped];
    }

    /**
     * Remove the pick face at this store. Secondary locations survive — if
     * one exists, it's promoted, so a product that's still stocked
     * somewhere doesn't end up looking unplaced.
     */
    private function clear(Product $product, int $storeId): bool
    {
        $rows = $product->locations()
            ->where('store_id', $storeId)
            ->whereNull('variant_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $primary = $rows->firstWhere('is_primary', true) ?? $rows->first();
        if ($primary === null) {
            return false;
        }

        $primary->delete();

        $next = $rows->firstWhere(fn (ProductLocation $r) => $r->id !== $primary->id);
        if ($next !== null) {
            $next->forceFill(['is_primary' => true])->save();
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    private function isEmpty(array $values): bool
    {
        foreach (array_merge(ProductLocation::COORDINATES, ['note']) as $key) {
            if (trim((string) ($values[$key] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
