<?php

namespace App\Policies;

use App\Models\ProductBatch;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Product batches. Viewing rides on the light `products.view` (the batches
 * list has always been a visibility surface for owners + pharmacists).
 *
 * Archiving needs its own key rather than reusing `products.delete`: removing a
 * batch is an inventory-integrity action, not a catalogue one, and a pharmacist
 * who may tidy batches is not necessarily allowed to delete products.
 *
 * The "must be empty" rule lives here as well as in the action — same
 * three-layer idiom the stock-adjustment delete uses (policy → controller
 * abort → action exception), so the UI can hide an affordance it would refuse.
 */
class ProductBatchPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('products.view');
    }

    public function view(User $user, ProductBatch $batch): bool
    {
        return $this->inStoreScope($user, $batch) && $user->hasPermission('products.view');
    }

    /**
     * Store-scoped (SEC #77): the controller's own guard was
     * `abort_unless(enforce_store_access($batch->store_id) !== null, 403)`,
     * but that helper is a query CLAMP, not a boolean gate — for an
     * unreachable store it returns the user's OWN store id, so `!== null` was
     * always true and the guard never fired.
     */
    public function delete(User $user, ProductBatch $batch): bool
    {
        return $this->inStoreScope($user, $batch)
            && $user->hasPermission('products.delete_batch')
            && bccomp((string) $batch->quantity, '0', 4) === 0;
    }

    /**
     * Same key as archiving: whoever may archive a batch may undo it. No
     * quantity rule — restoring is a pure visibility change and can't strand
     * anything, so an archive done by mistake is always reversible.
     */
    public function restore(User $user, ProductBatch $batch): bool
    {
        return $this->inStoreScope($user, $batch) && $user->hasPermission('products.delete_batch');
    }
}
