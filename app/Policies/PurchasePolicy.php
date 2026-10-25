<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Purchase authorization. Permissions are seeded by PermissionsSeeder:
 * `purchases.view`, `.create`, `.update`, `.delete`, `.receive`.
 * Super admins bypass via Gate::before.
 *
 * Per-record abilities are also store-scoped (SEC #90/#91): QA opened a Main
 * Store purchase as an Ahmedabad-only manager with Edit / Receive / Cancel /
 * Delete all live. `update` additionally guards purchase-return creation
 * (SEC #83).
 */
class PurchasePolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool { return $user->hasPermission('purchases.view'); }
    public function view(User $user, Purchase $p): bool   { return $this->inStoreScope($user, $p) && $user->hasPermission('purchases.view'); }
    public function create(User $user): bool  { return $user->hasPermission('purchases.create'); }
    public function update(User $user, Purchase $p): bool { return $this->inStoreScope($user, $p) && $user->hasPermission('purchases.update'); }
    public function delete(User $user, Purchase $p): bool { return $this->inStoreScope($user, $p) && $user->hasPermission('purchases.delete'); }
    public function receive(User $user, Purchase $p): bool { return $this->inStoreScope($user, $p) && $user->hasPermission('purchases.receive'); }
}
