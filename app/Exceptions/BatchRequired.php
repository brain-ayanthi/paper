<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a document is posted with a line for a batch-tracked product
 * that names no batch. Stock for such a product is only meaningful per batch:
 * posting without one moves the store-level quantity while every batch stays
 * where it was, so on-hand and the sum of its batches drift apart — and an
 * expiry-driven business (pharmacy) loses the one number it sells on.
 *
 * The draft stays editable; only the post is refused.
 */
class BatchRequired extends RuntimeException
{
    /** @param array<int, string> $productNames */
    public function __construct(public readonly array $productNames)
    {
        parent::__construct(__('inventory.errors.batch_required', [
            'products' => implode(', ', $productNames),
        ]));
    }
}
