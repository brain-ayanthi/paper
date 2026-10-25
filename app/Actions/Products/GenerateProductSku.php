<?php

namespace App\Actions\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\NumberFormat;

/**
 * Builds the next product SKU from the company's configured template
 * (Settings → Numbering, default `SKU-{seq:05}` → `SKU-00001`).
 *
 * Only used when the admin leaves the SKU field blank — a typed SKU is
 * always kept as-is. Shops that use manufacturer codes never see this.
 *
 * ## Why the taken-check includes trashed rows
 *
 * `products.sku` is UNIQUE at the database level with no `deleted_at` in
 * the index, so a soft-deleted product still occupies its SKU until
 * {@see \App\Actions\Concerns\FreesSoftDeletedUnique} tombstones it. Handing
 * out a SKU that only *looks* free would blow up the INSERT, so the scan
 * uses `withTrashed()` and skips over anything already taken. Variants
 * share the same namespace in practice (people scan and search across
 * both), so they're checked too.
 *
 * Race-safety: two admins saving at the same instant can still land on
 * the same number. {@see CreateProduct} catches the duplicate-key error
 * and asks for the next one, which is what actually makes this safe —
 * this class only has to be *usually* right.
 *
 * Hooks:
 *   - filter `product.sku.generated` → ($sku, $seq)
 */
class GenerateProductSku
{
    /** Safety valve for the skip-taken loop — far above any real collision run. */
    private const MAX_PROBE = 1000;

    public function __invoke(): string
    {
        $company = Company::current() ?? new Company();
        $format  = $company->numberFormat('sku');
        $when    = now();

        // Everything before the {seq} placeholder — e.g. `SKU-`. Products
        // are company-level so there's no store to render.
        $prefix = NumberFormat::prefix($format, null, $when);

        $seq = $this->highestSequence($prefix) + 1;

        for ($probe = 0; $probe < self::MAX_PROBE; $probe++, $seq++) {
            $candidate = NumberFormat::render($format, null, $when, $seq);

            if (! $this->isTaken($candidate)) {
                return (string) apply_filters('product.sku.generated', $candidate, $seq);
            }
        }

        // Pathological catalogue (or a format with no {seq}) — fall back to
        // something unique rather than looping forever or returning a dup.
        return $prefix.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /**
     * Highest sequence already issued under this prefix. Reads the
     * catalogue rather than a counter table so a store that already uses
     * this SKU shape continues its own numbering instead of colliding
     * from 1. Trashed rows count — they still hold their SKU.
     */
    private function highestSequence(string $prefix): int
    {
        if ($prefix === '') {
            return 0;
        }

        $latest = Product::withTrashed()
            ->where('sku', 'like', $prefix.'%')
            // Longest first, then lexically: keeps `SKU-00010` above
            // `SKU-9` where padding widths have been mixed over time.
            ->orderByRaw('LENGTH(sku) DESC')
            ->orderByDesc('sku')
            ->value('sku');

        return $latest ? NumberFormat::extractSeq($latest) : 0;
    }

    private function isTaken(string $sku): bool
    {
        return Product::withTrashed()->where('sku', $sku)->exists()
            || ProductVariant::withTrashed()->where('sku', $sku)->exists();
    }
}
