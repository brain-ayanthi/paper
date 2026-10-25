<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when removing a {@see App\Models\ProductVariant} that still
 * holds physical stock — a non-zero (or reserved) `product_stock_levels`
 * row, or a batch with quantity left.
 *
 * Variants soft-delete, so the FK never cascades: the stock row survives
 * pointing at a dead variant. The quantity then vanishes from the
 * cashier ("Stock not listed yet") while still counting toward the
 * Stock Levels totals. Refusing the delete keeps that from happening
 * silently — the user zeroes the variant out with an adjustment first,
 * which leaves a real ledger entry for where the stock went.
 *
 * `$quantity` is the total on-hand across every store; `$label` carries
 * the variant's display label so the message can name the exact SKU.
 */
class ProductVariantHasStock extends RuntimeException
{
    public function __construct(
        public readonly string $quantity,
        public readonly string $label = '',
    ) {
        parent::__construct("Cannot delete variant — {$quantity} unit(s) still in stock.");
    }
}
