<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductLocation;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a product's `product_locations` rows against the editor's
 * payload.
 *
 * Unlike {@see SyncProductPrices} (one override per store, keyed by store
 * id) a product may occupy SEVERAL places in one store — front rack,
 * godown, fridge, controlled cabinet — so the payload is a flat LIST and
 * each row carries its own `store_id`:
 *
 *   $rows[] = [
 *       'id'         => 12 | null,       // existing row, or null for new
 *       'store_id'   => 3,
 *       'kind'       => 'primary',       // see ProductLocation::KINDS
 *       'is_primary' => true,
 *       'aisle'      => 'A3',
 *       'rack'       => 'R-12',
 *       'shelf'      => '2',
 *       'bin'        => '',
 *       'note'       => 'behind counter',
 *   ];
 *
 * Semantics:
 *   - A row with no coordinates AND no note is dropped — that's an empty
 *     form row the user added and never filled in, not a location.
 *   - Rows that exist in the DB but not in the payload are DELETED, but
 *     only within `$storeScope`. Without that bound, a manager who can
 *     only see store 1 would silently wipe store 2's shelf locations
 *     every time they saved a product.
 *   - Exactly one `is_primary` per (store, variant) group. MySQL has no
 *     partial unique index and this is business logic anyway, so it is
 *     enforced here: extra flags are cleared, and a group with none gets
 *     its first row promoted. Every store with locations therefore always
 *     has a pick face to print on a label.
 *   - `sort_key` is always recomputed, never trusted from the client.
 *
 * `$variantId` mirrors SyncProductPrices: null = product-level rows (all
 * the editor writes today), set = a specific variant's own bins (v1.1).
 *
 * Hook points:
 *   - filter `product.locations.payload` → adjust the incoming rows
 *   - action `product.locations.synced`  → fires after sync completes
 */
class SyncProductLocations
{
    /**
     * @param  array<int, array<string, mixed>>  $rows        Validated payload from ProductRequest.
     * @param  array<int, int>                   $storeScope  Store ids the editor rendered — the
     *                                                        only stores whose rows may be deleted.
     */
    public function __invoke(Product $product, array $rows, array $storeScope, ?int $variantId = null): void
    {
        $rows = apply_filters('product.locations.payload', $rows, $product);

        $scope = array_values(array_unique(array_map('intval', $storeScope)));
        if ($scope === []) {
            // Nothing the caller is allowed to manage — do nothing rather
            // than guess. A no-op is always safe; a wrong delete isn't.
            return;
        }

        DB::transaction(function () use ($product, $rows, $scope, $variantId) {
            $existing = $product->locations()
                ->whereIn('store_id', $scope)
                ->when(
                    $variantId === null,
                    fn ($q) => $q->whereNull('variant_id'),
                    fn ($q) => $q->where('variant_id', $variantId),
                )
                ->get()
                ->keyBy('id');

            $keptIds = [];
            $order   = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $storeId = (int) ($row['store_id'] ?? 0);
                if ($storeId <= 0 || ! in_array($storeId, $scope, true)) {
                    continue;
                }

                $values = [];
                foreach (ProductLocation::COORDINATES as $key) {
                    $values[$key] = $this->clean($row[$key] ?? null);
                }
                $values['note'] = $this->clean($row['note'] ?? null, 64);

                // An untouched blank form row is not a location. Dropping
                // it here (rather than validating it away) lets the editor
                // keep an empty "add another" row on screen harmlessly.
                if (! array_filter($values, fn ($v) => $v !== null)) {
                    continue;
                }

                $kind = (string) ($row['kind'] ?? 'primary');
                if (! in_array($kind, ProductLocation::KINDS, true)) {
                    $kind = 'primary';
                }

                $order[$storeId] = ($order[$storeId] ?? -1) + 1;

                $values['kind']       = $kind;
                $values['is_primary'] = filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $values['sort_key']   = ProductLocation::makeSortKey($values);
                $values['sort_order'] = $order[$storeId];

                // Only adopt an `id` that really belongs to this product,
                // this store and this variant — a tampered payload must not
                // let one product rewrite another's shelf location.
                $id     = (int) ($row['id'] ?? 0);
                $target = $id > 0 ? $existing->get($id) : null;
                if ($target !== null && (int) $target->store_id !== $storeId) {
                    $target = null;
                }

                if ($target !== null) {
                    $target->update($values);
                    $keptIds[] = $target->id;
                } else {
                    $created = $product->locations()->create($values + [
                        'store_id'   => $storeId,
                        'variant_id' => $variantId,
                    ]);
                    $keptIds[] = $created->id;
                }
            }

            // Anything left in scope that the payload no longer mentions
            // was removed in the editor.
            $removed = $existing->keys()->diff($keptIds);
            if ($removed->isNotEmpty()) {
                ProductLocation::query()->whereIn('id', $removed->all())->delete();
            }

            $this->enforceSinglePrimary($product, $scope, $variantId);
        });

        do_action('product.locations.synced', $product);
    }

    /**
     * One pick face per (store, variant): clear duplicate flags, and
     * promote the first row when a store has locations but no primary.
     *
     * Runs off freshly-read rows rather than the payload, so it also
     * repairs data written by an import or a plugin.
     *
     * @param  array<int, int>  $scope
     */
    private function enforceSinglePrimary(Product $product, array $scope, ?int $variantId): void
    {
        $rows = $product->locations()
            ->whereIn('store_id', $scope)
            ->when(
                $variantId === null,
                fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $variantId),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($rows->groupBy('store_id') as $group) {
            // Prefer an explicitly flagged row; otherwise the row the user
            // put first, which in practice is the pick face.
            $winner = $group->firstWhere('is_primary', true) ?? $group->first();

            foreach ($group as $row) {
                $shouldBePrimary = $row->id === $winner->id;
                if ((bool) $row->is_primary !== $shouldBePrimary) {
                    $row->forceFill(['is_primary' => $shouldBePrimary])->save();
                }
            }
        }
    }

    /** Trim to null, and clip to the column width so a long paste can't 1406. */
    private function clean(mixed $value, int $max = 16): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
