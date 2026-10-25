<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Stock movements are the audit trail — readable by anyone with
 * `products.view`, never editable from the UI. Mistakes get corrected
 * by a counter-entry through the adjustment flow.
 */
class StockMovementPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool { return $user->hasPermission('products.view'); }
    public function view(User $user, StockMovement $m): bool { return $this->inStoreScope($user, $m) && $user->hasPermission('products.view'); }
}
