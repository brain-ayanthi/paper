<?php

namespace App\Policies;

use App\Models\Terminal;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Terminal authorization (docs/features/hardware.md §11). Viewing is
 * gated by `terminals.view`; creating, editing, configuring hardware,
 * and deleting all share `terminals.configure`. Super admins bypass via
 * AuthServiceProvider's Gate::before.
 *
 * Per-record abilities are store-scoped (SEC #17/#46/#90/#94) — `select`
 * already checked `canAccessStore`, but edit/update/destroy did not, so a
 * manager could reconfigure or delete another branch's till.
 */
class TerminalPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool { return $user->hasPermission('terminals.view'); }

    public function view(User $user, Terminal $terminal): bool { return $this->inStoreScope($user, $terminal) && $user->hasPermission('terminals.view'); }

    public function create(User $user): bool { return $user->hasPermission('terminals.configure'); }

    public function update(User $user, Terminal $terminal): bool { return $this->inStoreScope($user, $terminal) && $user->hasPermission('terminals.configure'); }

    public function delete(User $user, Terminal $terminal): bool { return $this->inStoreScope($user, $terminal) && $user->hasPermission('terminals.configure'); }
}
