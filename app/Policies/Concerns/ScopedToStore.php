<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The store boundary for per-record authorization.
 *
 * Security register SEC #90 (with #12, #14, #15, #17, #45, #46, #52, #54, #62,
 * #73, #77, #83, #84, #89, #91-#96 as its instances). Store isolation used to
 * be enforced only when BUILDING LIST QUERIES — `enforce_store_access()` and
 * `accessible_stores()` — and on zero per-record authorizations. Not one of the
 * 27 policies referenced `store_id`. Because detail and action routes bind
 * straight to a record id, any permission-holder could open or mutate another
 * branch's records by guessing the id. Confirmed live: a manager assigned only
 * to Ahmedabad refunded a Main Store sale end to end.
 *
 * Every policy guarding a model with a `store_id` mixes this in and calls
 * {@see inStoreScope()} alongside its permission check.
 *
 * Two deliberate decisions:
 *
 *  - Super admins pass. They already bypass policies wholesale through
 *    `Gate::before` (AuthServiceProvider), so this is belt-and-braces for code
 *    paths that call a policy method directly rather than through the Gate.
 *
 *  - A NULL `store_id` passes. Some records are company-level by design
 *    (a customer payment, a journal entry). Failing closed there would deny
 *    access to records that have no store to compare against; the store
 *    boundary is simply not the right control for them. QA flagged those
 *    separately as "company scope, not a store leak".
 */
trait ScopedToStore
{
    /**
     * Does this record belong to a store the user may reach?
     *
     * `$column` lets multi-store records name the side that matters — a stock
     * transfer is dispatched out of `from_store_id` and received into
     * `to_store_id`.
     */
    protected function inStoreScope(User $user, ?Model $record, string $column = 'store_id'): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if ($record === null) {
            return true;
        }

        $storeId = $record->getAttribute($column);

        if ($storeId === null) {
            return true;
        }

        return $user->canAccessStore((int) $storeId);
    }

    /**
     * True when the user can reach ANY of the named stores on the record.
     * Used for read access to a record that spans two stores — the receiving
     * branch has a legitimate interest in a transfer it did not send.
     */
    protected function inAnyStoreScope(User $user, ?Model $record, string ...$columns): bool
    {
        if ($user->is_super_admin || $record === null) {
            return true;
        }

        foreach ($columns as $column) {
            if ($this->inStoreScope($user, $record, $column)) {
                return true;
            }
        }

        return false;
    }
}
