<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Sale authorization. Permissions seeded by PermissionsSeeder:
 *   sales.view_own / sales.view_all / sales.cross_store_view
 *   sales.create / sales.update / sales.void / sales.refund
 *   sales.discount / sales.discount_above_threshold
 *   sales.held.create / sales.held.resume_others / sales.print_receipt
 *
 * `viewAny` falls through to view_all → view_own. `view` further checks
 * cashier ownership when only view_own is held — staff see their own
 * tickets but not anyone else's unless granted view_all.
 *
 * Super admins bypass via Gate::before. Slice 1 ships viewAny/view/create.
 * void / refund flows expose their gates in their respective slices.
 */
class SalePolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sales.view_all')
            || $user->hasPermission('sales.view_own');
    }

    public function view(User $user, Sale $sale): bool
    {
        if (! $this->inStoreScope($user, $sale)) {
            return false;
        }
        if ($user->hasPermission('sales.view_all')) {
            return true;
        }
        if ($user->hasPermission('sales.view_own')) {
            return (int) $sale->cashier_id === (int) $user->id;
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('sales.create');
    }

    public function void(User $user, Sale $sale): bool
    {
        return $this->inStoreScope($user, $sale) && $user->hasPermission('sales.void');
    }

    public function refund(User $user, Sale $sale): bool
    {
        return $this->inStoreScope($user, $sale) && $user->hasPermission('sales.refund');
    }
}
