<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductLocation;
use Illuminate\Support\Facades\DB;

/**
 * Set (or update) the PICK FACE location of one product at one store.
 *
 * This is the write path for every bulk route into shelf locations — the
 * CSV/XLSX importer and the products list's "Set location" bulk action —
 * as opposed to {@see SyncProductLocations}, which reconciles the full
 * per-store list from the product editor.
 *
 * The semantics are deliberately DIFFERENT from the editor's, and the
 * difference is the whole point:
 *
 *   - **Blank means "leave alone", never "erase".** A customer
 *     re-importing a price list that happens to carry an empty Rack
 *     column must not have every shelf location they typed wiped out.
 *     SyncProductLocations treats an all-blank row as a delete, which is
 *     right for the editor and catastrophic here.
 *   - **Partial updates are normal.** Setting just `rack` across 500
 *     products leaves each product's aisle and bin untouched.
 *   - It only ever touches the pick face. A product's godown / fridge /
 *     controlled-cabinet rows are invisible to bulk tools, because a flat
 *     spreadsheet has no way to express several places per product.
 *
 * Because nothing here deletes, the worst a wrong bulk run can do is set
 * a coordinate you then correct — never lose data you can't get back.
 * Clearing is an explicit, separate operation
 * ({@see \App\Actions\Products\BulkAssignProductLocation} in `clear` mode)
 * or a row-level delete in the editor.
 *
 * Hook points:
 *   - filter `product.location.upsert.values` → adjust the incoming values
 *   - action `product.location.upserted`      → fires after a row is written
 */
class UpsertPrimaryProductLocation
{
    /**
     * @param  array<string, mixed>  $values  Any of aisle/rack/shelf/bin/note.
     *                                        Blank or absent keys are left as they were.
     * @return ProductLocation|null           The written row, or null when there was
     *                                        nothing to write (all values blank).
     */
    public function __invoke(Product $product, int $storeId, array $values): ?ProductLocation
    {
        $values = apply_filters('product.location.upsert.values', $values, $product, $storeId);

        $set = [];
        foreach (ProductLocation::COORDINATES as $key) {
            $clean = $this->clean($values[$key] ?? null, 16);
            if ($clean !== null) {
                $set[$key] = $clean;
            }
        }

        $note = $this->clean($values['note'] ?? null, 64);
        if ($note !== null) {
            $set['note'] = $note;
        }

        // Nothing supplied → no-op. This is the guard that makes a
        // narrow re-import (prices only) harmless.
        if ($set === [] || $storeId <= 0) {
            return null;
        }

        $row = DB::transaction(function () use ($product, $storeId, $set) {
            // Reuse the existing pick face when there is one, so a second
            // import updates in place instead of stacking duplicate rows.
            $row = $product->locations()
                ->where('store_id', $storeId)
                ->whereNull('variant_id')
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($row === null) {
                $row = $product->locations()->make($set + [
                    'store_id'   => $storeId,
                    'variant_id' => null,
                    'kind'       => 'primary',
                    'sort_order' => 0,
                ]);
                $row->product_id = $product->id;
            } else {
                $row->fill($set);
            }

            // Always recomputed from the final coordinates — never taken
            // from the caller, so a spreadsheet can't poison walk order.
            $row->sort_key   = ProductLocation::makeSortKey($row->only(ProductLocation::COORDINATES));
            $row->is_primary = true;
            $row->save();

            // One pick face per store, same invariant SyncProductLocations
            // enforces from the editor.
            $product->locations()
                ->where('store_id', $storeId)
                ->whereNull('variant_id')
                ->where('id', '!=', $row->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            return $row;
        });

        do_action('product.location.upserted', $product, $row);

        return $row;
    }

    /** Trim to null, clip to the column width. */
    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
