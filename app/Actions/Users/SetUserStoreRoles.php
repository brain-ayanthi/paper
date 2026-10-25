<?php

namespace App\Actions\Users;

use App\Models\Role;
use App\Models\User;
use App\Support\PrivilegeSubset;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Sync a user's store memberships + their role per store (the
 * `store_user` pivot). Keeps `default_store_id` valid — pointed at the
 * first assigned store when the current default is unset or no longer
 * granted. Bumps the permission cache so changes take effect at once.
 */
class SetUserStoreRoles
{
    /** @param array<int, int> $storeRoles  store_id => role_id */
    public function __invoke(User $user, array $storeRoles): void
    {
        $this->assertRolesAssignable($storeRoles);

        $sync = [];
        foreach ($storeRoles as $storeId => $roleId) {
            $sync[(int) $storeId] = ['role_id' => (int) $roleId];
        }

        $user->stores()->sync($sync);

        $storeIds = array_keys($sync);
        if (! $user->default_store_id || ! in_array((int) $user->default_store_id, $storeIds, true)) {
            $user->forceFill(['default_store_id' => $storeIds[0] ?? null])->save();
        }

        User::bumpPermissionsVersion();
    }

    /**
     * Privilege-subset guard (SEC #88, vector 2). Assigning a role that holds
     * permissions the actor lacks is just an indirect grant of those
     * permissions — QA's example being a `users.update` holder handing
     * themselves the built-in Admin role.
     *
     * UserRequest rejects this with a friendly message; this is the backstop.
     * A no-op with no authenticated actor (seeders, installer, console).
     *
     * @param  array<int, int>  $storeRoles
     *
     * @throws AuthorizationException
     */
    private function assertRolesAssignable(array $storeRoles): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $actor->is_super_admin) {
            return;
        }

        $roleIds = array_unique(array_map('intval', array_values($storeRoles)));

        foreach (Role::query()->whereIn('id', $roleIds)->with('permissions:id')->get() as $role) {
            if (! PrivilegeSubset::canAssignRole($actor, $role)) {
                throw new AuthorizationException(
                    "Cannot assign the \"{$role->name}\" role — it holds permissions you do not have.",
                );
            }
        }
    }
}
