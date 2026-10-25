<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Shift access — opening + closing your own shift only needs
 * `shifts.open` / `shifts.close_own`; closing someone else's needs
 * `shifts.close_others`; the index/show pages need either `shifts.view`
 * or `shifts.view_all`.
 *
 * Laravel auto-discovers App\Models\Shift → App\Policies\ShiftPolicy so
 * no AuthServiceProvider wiring is required.
 */
class ShiftPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shifts.view_all')
            || $user->hasPermission('shifts.open')
            || $user->hasPermission('shifts.close_own');
    }

    /**
     * Store-scoped (SEC #90/#92): a shift's X/Z report exposes another
     * branch's cash-drawer counts. Own-shift access still requires the shift
     * to be in reach — a user cannot hold a shift in a store they have since
     * lost access to.
     */
    public function view(User $user, Shift $shift): bool
    {
        if (! $this->inStoreScope($user, $shift)) return false;
        if ($user->hasPermission('shifts.view_all')) return true;
        if ((int) $shift->user_id === (int) $user->id) return true;
        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shifts.open');
    }

    public function close(User $user, Shift $shift): bool
    {
        if (! $this->inStoreScope($user, $shift)) return false;
        if (! $shift->isOpen()) return false;
        if ((int) $shift->user_id === (int) $user->id) {
            return $user->hasPermission('shifts.close_own');
        }
        return $user->hasPermission('shifts.close_others');
    }
}
