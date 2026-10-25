<?php

namespace App\Actions\Users\Concerns;

use App\Exceptions\UserActionDenied;
use App\Models\User;

/**
 * The two rules that keep somebody able to get in — security register SEC #38
 * (linked defect #6).
 *
 * They existed only inside DeactivateUser, which meant the ordinary edit form
 * bypassed both: `UpdateUser` filled `is_active` straight from the request, so
 * an admin could switch themselves off, and the last active super admin could
 * be deactivated through the edit screen even though the dedicated deactivate
 * action refused it. Locking every administrator out of a self-hosted install
 * is not recoverable from the UI.
 *
 * Centralised here so any future write path picks them up by using the trait
 * rather than by remembering to re-implement them.
 */
trait ProtectsAdminAccess
{
    /**
     * Refuse a deactivation that would lock the actor out of their own
     * account, or remove the last way into the system.
     *
     * @throws UserActionDenied
     */
    protected function assertMayDeactivate(User $user, ?int $actingUserId = null): void
    {
        if ($actingUserId !== null && $actingUserId === $user->id) {
            throw new UserActionDenied('self');
        }

        if ($user->is_super_admin && $this->isLastActiveSuperAdmin($user)) {
            throw new UserActionDenied('last_super_admin');
        }
    }

    /**
     * Refuse stripping super-admin from the only remaining active one.
     *
     * @throws UserActionDenied
     */
    protected function assertMayDemoteSuperAdmin(User $user): void
    {
        if ($user->is_super_admin && $this->isLastActiveSuperAdmin($user)) {
            throw new UserActionDenied('last_super_admin');
        }
    }

    protected function isLastActiveSuperAdmin(User $user): bool
    {
        return User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
