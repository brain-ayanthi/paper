<?php

namespace App\Actions\Products;

use App\Exceptions\ProductVariantHasSales;
use App\Exceptions\ProductVariantHasStock;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles a product's `product_variants` rows against an array
 * payload from the editor's attribute-matrix generator.
 *
 * Each item in the payload may carry:
 *   - `id`           — existing variant to update (omit / null = create new)
 *   - `combo`        — { attrName => value } map; stored as the variant's
 *                      `attributes` JSON and the source of its display label
 *   - `sku`          — required, unique across the product_variants table
 *   - `barcode`      — optional, unique when set
 *   - `cost_price`   — optional override
 *   - `selling_price`— required (variant price is the source of truth)
 *   - `is_active`    — bool, defaults true
 *   - `store_prices` — { storeId => {cost,selling,mrp} } per-store overrides
 *                      for THIS variant, persisted via {@see SyncProductPrices}
 *
 * Behavior:
 *   - Items in payload with an `id` → update that variant.
 *   - Items in payload WITHOUT an `id` → rebind to an existing variant of
 *     the same product carrying the same SKU or the same attribute
 *     combination (soft-deleted ones included, which are restored); only
 *     when nothing matches is a new variant created.
 *   - Existing variants NOT in the payload → soft-delete (with sales and
 *     stock guards); their per-store price rows are removed too.
 *
 * ── Why the rebind exists ─────────────────────────────────────────────
 * The editor's matrix generator re-keys its rows whenever the attribute
 * set changes, and a row it can't re-key posts with `id = null`. Taken
 * literally that means "delete the old variant, create a new one" — and
 * because variants soft-delete, the FK never cascades, so every
 * `product_stock_levels` / `product_batches` row keeps pointing at the
 * dead variant. On-hand quantities silently detach from the product the
 * cashier can actually sell ("Stock not listed yet") while still counting
 * toward the Stock Levels totals.
 *
 * Matching on SKU / combo before creating makes an id-less row idempotent:
 * the same combination lands on the same variant row, stock included. The
 * guards below are the second line of defence for the rows that genuinely
 * are being removed.
 *
 * The whole thing runs in a transaction so a mid-sync failure doesn't
 * leave the product with a half-updated variant set.
 *
 * Guards on delete:
 *   - a variant already on a `sale_items` row can't be deleted — removing
 *     it would orphan history. Throws {@see ProductVariantHasSales}.
 *   - a variant still holding stock can't be deleted — the quantity would
 *     be stranded on a dead row. Throws {@see ProductVariantHasStock}.
 * The controller surfaces a 422 message naming the offending variant.
 *
 * Hook points:
 *   - filter `product.variants.payload` → adjust the incoming array
 *   - action `product.variants.synced`   → fires after sync completes
 */
class SyncProductVariants
{
    public function __construct(private SyncProductPrices $syncPrices) {}

    /** @param array<int, array<string, mixed>> $payload */
    public function __invoke(Product $product, array $payload): void
    {
        $payload = apply_filters('product.variants.payload', $payload, $product);

        DB::transaction(function () use ($product, $payload) {
            // Every variant of this product, trashed included — a trashed
            // row is a rebind candidate for a payload row whose combo or
            // SKU matches it (see the class docblock).
            $existing = $product->variants()->withTrashed()->get();

            // ── Resolve each payload row to its target variant ────
            // Done up-front, BEFORE any delete, so the delete pass knows
            // which rows are actually surviving under a new identity.
            $targets = [];   // payload index → ProductVariant|null (null = create)
            $claimed = [];   // variant ids already spoken for

            foreach ($payload as $i => $row) {
                $match = $this->matchExisting($existing, $row, $claimed);
                $targets[$i] = $match;
                if ($match) {
                    $claimed[] = $match->id;
                }
            }

            // ── Delete the variants the user removed ──────────────
            $existing
                ->reject(fn (ProductVariant $v) => $v->trashed() || in_array($v->id, $claimed, true))
                ->each(function (ProductVariant $variant) use ($product) {
                    $this->assertNoLiveSales($variant);
                    $this->assertNoStock($variant);
                    // Drop the variant's per-store overrides first — the
                    // FK cascades on hard delete, but variants soft-delete,
                    // so clean them up explicitly to avoid orphan rows.
                    $product->prices()->where('variant_id', $variant->id)->delete();
                    $variant->delete();
                });

            // ── Upsert each variant in the payload ────────────────
            foreach ($payload as $i => $row) {
                $cost    = $row['cost_price']    ?? null;
                $selling = $row['selling_price'] ?? null;
                $mrp     = $row['mrp']           ?? null;

                // Combo: { attrName => value }. Stored as the variant's
                // attributes JSON; the model's `label` accessor joins the
                // values ("Red · S") when no explicit label key exists.
                $combo = $this->normalizeCombo($row['combo'] ?? []);

                $variant = $targets[$i] ?? null;

                $attrs = [
                    // Blank → derive from the parent SKU + the combo's values
                    // (`SHIRT-RED-S`). Matters most when the parent SKU was
                    // itself auto-generated: the browser had nothing to build
                    // a suggestion from at the time the matrix was created.
                    'sku'           => $this->resolveSku($product, $row, $combo, $variant?->id),
                    'barcode'       => isset($row['barcode']) && $row['barcode'] !== '' ? (string) $row['barcode'] : null,
                    'attributes'    => $combo,
                    'is_active'     => (bool) ($row['is_active'] ?? true),
                ];

                // Missing price keys mean the active pricing policy hid
                // product-level prices. Preserve legacy fallback values on an
                // existing variant; new variants legitimately start null and
                // receive their authoritative prices with their batches.
                if (! $variant || array_key_exists('cost_price', $row)) {
                    $attrs['cost_price'] = $cost !== null && $cost !== '' ? $cost : null;
                }
                if (! $variant || array_key_exists('selling_price', $row)) {
                    $attrs['selling_price'] = $selling !== null && $selling !== '' ? $selling : null;
                }
                if (! $variant || array_key_exists('mrp', $row)) {
                    $attrs['mrp'] = $mrp !== null && $mrp !== '' ? $mrp : null;
                }

                if ($variant) {
                    // Rebinding onto a row the user had previously removed
                    // brings it (and its stock) back rather than minting a
                    // duplicate SKU alongside the stranded original.
                    if ($variant->trashed()) {
                        $variant->restore();
                    }
                    $variant->update($attrs);
                } else {
                    $variant = $product->variants()->create($attrs);
                }

                // Per-store overrides for this specific variant.
                if (array_key_exists('store_prices', $row)) {
                    ($this->syncPrices)(
                        $product,
                        is_array($row['store_prices']) ? $row['store_prices'] : [],
                        $variant->id,
                    );
                }
            }
        });

        do_action('product.variants.synced', $product);
    }

    /**
     * The existing variant a payload row should write to, or null when it
     * is genuinely new. Rows already claimed by an earlier payload entry
     * are skipped so two rows can never collapse onto one variant.
     *
     * Match order — most explicit first:
     *   1. `id` the editor sent back (the untouched-matrix path)
     *   2. the typed SKU — stable identity the user controls, and what's
     *      printed on the shelf label
     *   3. the attribute combination — "Color=red|size=s", normalized, so
     *      case and attribute order don't matter
     *
     * @param Collection<int, ProductVariant> $existing
     * @param array<string, mixed>            $row
     * @param array<int, int>                 $claimed
     */
    private function matchExisting(Collection $existing, array $row, array $claimed): ?ProductVariant
    {
        $available = $existing->reject(fn (ProductVariant $v) => in_array($v->id, $claimed, true));

        if (! empty($row['id'])) {
            // Defence against payload tampering: `$existing` only ever
            // holds this product's own variants, so an id from another
            // product simply finds nothing and falls through.
            $byId = $available->firstWhere('id', (int) $row['id']);
            if ($byId) {
                return $byId;
            }
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku !== '') {
            $bySku = $available->first(
                fn (ProductVariant $v) => strcasecmp((string) $v->sku, $sku) === 0
            );
            if ($bySku) {
                return $bySku;
            }
        }

        $signature = $this->comboSignature($this->normalizeCombo($row['combo'] ?? []));
        if ($signature !== '') {
            return $available->first(
                fn (ProductVariant $v) => $this->comboSignature(
                    $this->normalizeCombo($v->getAttribute('attributes') ?? [])
                ) === $signature
            );
        }

        return null;
    }

    /**
     * Trim the combo map and drop blank values.
     *
     * @param  mixed $combo
     * @return array<string, string>
     */
    private function normalizeCombo(mixed $combo): array
    {
        $out = [];
        foreach ((array) $combo as $attrName => $value) {
            if (is_array($value)) continue;
            $value = trim((string) $value);
            if ($value === '') continue;
            $out[(string) $attrName] = $value;
        }

        return $out;
    }

    /**
     * Order- and case-insensitive identity for a combination:
     * `color=red|size=s`. Reordering the attributes in the editor, or
     * re-typing "Red" as "red", still points at the same variant.
     *
     * @param array<string, string> $combo
     */
    private function comboSignature(array $combo): string
    {
        $parts = [];
        foreach ($combo as $name => $value) {
            $parts[] = mb_strtolower(trim((string) $name)).'='.mb_strtolower($value);
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * The variant's SKU: whatever was typed, else one derived from the
     * parent SKU and the combination's values.
     *
     * Mirrors `suggestSku()` in resources/js/admin/product-editor.js so a
     * variant created with the field left blank looks identical to one the
     * browser suggested. Falls back to a numeric suffix when the derived
     * name is already taken (two attributes whose values normalise the
     * same, or a re-created combo whose old SKU is still soft-deleted).
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $combo
     */
    private function resolveSku(Product $product, array $row, array $combo, ?int $variantId = null): string
    {
        $typed = trim((string) ($row['sku'] ?? ''));
        if ($typed !== '') {
            return $typed;
        }

        $base  = strtoupper(preg_replace('/\s+/', '', (string) $product->sku));
        $parts = array_map(
            fn ($v) => strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) $v)),
            array_values($combo),
        );

        $candidate = implode('-', array_filter([$base, ...$parts], fn ($p) => $p !== ''));
        if ($candidate === '') {
            $candidate = $base !== '' ? $base : 'VAR';
        }

        $sku = $candidate;
        // The row we're about to write doesn't collide with itself — pass
        // the RESOLVED variant, not `$row['id']`, so a rebound row keeps
        // the SKU it already had instead of gaining a `-2` suffix.
        for ($n = 2; $this->skuTaken($sku, $variantId); $n++) {
            $sku = $candidate.'-'.$n;
        }

        return $sku;
    }

    /** Trashed rows count — the DB unique index doesn't exclude them. */
    private function skuTaken(string $sku, mixed $exceptId = null): bool
    {
        return ProductVariant::withTrashed()
            ->where('sku', $sku)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists()
            || Product::withTrashed()->where('sku', $sku)->exists();
    }

    private function assertNoLiveSales(ProductVariant $variant): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        $count = DB::table('sale_items')
            ->where('variant_id', $variant->id)
            ->count();

        if ($count > 0) {
            throw new ProductVariantHasSales($count, (string) ($variant->label ?? $variant->sku));
        }
    }

    /**
     * Refuse to remove a variant that still holds stock anywhere.
     *
     * Live quantity only — a variant that was stocked once and has since
     * been counted down to zero is free to go, and its `stock_movements`
     * history stays readable through the product. What can't be allowed
     * is on-hand (or reserved, or batched) quantity detaching from a
     * sellable SKU: zero it out with an adjustment first, so the ledger
     * records where it went.
     */
    private function assertNoStock(ProductVariant $variant): void
    {
        $onHand   = 0.0;
        $reserved = 0.0;

        if (Schema::hasTable('product_stock_levels')) {
            $row = DB::table('product_stock_levels')
                ->where('variant_id', $variant->id)
                ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(reserved_quantity), 0) as reserved')
                ->first();

            $onHand   = (float) ($row->qty      ?? 0);
            $reserved = (float) ($row->reserved ?? 0);
        }

        // A batch-tracked variant whose level row is somehow missing still
        // holds countable stock — worth blocking on.
        if ($onHand == 0.0 && $reserved == 0.0 && Schema::hasTable('product_batches')) {
            $onHand = (float) DB::table('product_batches')
                ->where('variant_id', $variant->id)
                ->sum('quantity');
        }

        if ($onHand == 0.0 && $reserved == 0.0) {
            return;
        }

        throw new ProductVariantHasStock(
            $this->formatQuantity($onHand != 0.0 ? $onHand : $reserved),
            (string) ($variant->label ?? $variant->sku),
        );
    }

    /** `25.0000` → `25`, `1.5000` → `1.5` — for the user-facing message. */
    private function formatQuantity(float $quantity): string
    {
        $trimmed = number_format($quantity, 4, '.', '');
        $trimmed = rtrim(rtrim($trimmed, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
