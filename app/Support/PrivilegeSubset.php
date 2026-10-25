<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The privilege-subset rule: you cannot hand out authority you do not hold.
 *
 * Security register SEC #88. Role management had no subset check anywhere, so
 * escalation was a two-click job. QA proved vector 1 live: signed in as a
 * Manager holding `roles.manage`, opened their OWN role, ticked the dangerous
 * `backup.restore` they did not hold, saved, and the role went 98 → 99
 * permissions — effective on the next request, because UpdateRole bumps the
 * permission-cache version. Vector 2 shares the root: `SetUserStoreRoles`
 * synced whatever `role_id` was posted, so a user holding only `users.update`
 * could assign the built-in Admin role (every permission) to themselves.
 *
 * `is_super_admin` was already gated by the `manageSuperAdmin` ability — which
 * is precisely why escalation routed through roles instead.
 *
 * Scope note: an actor's permissions are resolved for their ACTIVE store (see
 * HasPermissions::effectivePermissions), while roles are global. We compare
 * against the active-store set because that is the authority the actor is
 * actually operating with, and it is the same set every other `hasPermission()`
 * call in the app uses. A multi-store actor therefore cannot use authority
 * held in one branch to mint a role while operating in another.
 */
class PrivilegeSubset
{
    /**
     * Permission ids the actor may grant — everything they themselves hold.
     * Super admins hold everything.
     *
     * @return list<int>
     */
    public static function grantableIds(User $actor): array
    {
        if ($actor->is_super_admin) {
            return Permission::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $keys = $actor->effectivePermissions();

        if ($keys === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('key', $keys)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The subset of `$permissionIds` the actor does NOT hold — empty when the
     * grant is legitimate.
     *
     * @param  list<int>  $permissionIds
     * @return list<int>
     */
    public static function idsBeyond(array $permissionIds, User $actor): array
    {
        if ($actor->is_super_admin) {
            return [];
        }

        return array_values(array_diff(
            array_map('intval', $permissionIds),
            self::grantableIds($actor),
        ));
    }

    /**
     * Human-readable keys for the permissions an actor tried to grant but does
     * not hold — used to build a validation message that names the offenders
     * rather than failing opaquely.
     *
     * @param  list<int>  $permissionIds
     * @return list<string>
     */
    public static function keysBeyond(array $permissionIds, User $actor): array
    {
        $ids = self::idsBeyond($permissionIds, $actor);

        if ($ids === []) {
            return [];
        }

        return Permission::query()->whereIn('id', $ids)->orderBy('key')->pluck('key')->all();
    }

    /**
     * May the actor assign this role to somebody (including themselves)?
     *
     * False when the role carries any permission the actor lacks — assigning
     * such a role is just a slower way of granting those permissions.
     */
    public static function canAssignRole(User $actor, Role $role): bool
    {
        if ($actor->is_super_admin) {
            return true;
        }

        $roleIds = $role->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all();

        return self::idsBeyond($roleIds, $actor) === [];
    }

    /**
     * Hard guard for the Actions — the Form Requests already reject this with
     * a friendly message, so reaching here means a caller bypassed them.
     *
     * Deliberately a no-op when there is no authenticated user: seeders, the
     * installer and console commands legitimately mint roles with no actor,
     * and there is no privilege to be exceeded in those contexts.
     *
     * @param  list<int>  $permissionIds
     *
     * @throws AuthorizationException
     */
    public static function assertGrantable(array $permissionIds): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $beyond = self::keysBeyond($permissionIds, $actor);

        if ($beyond !== []) {
            throw new AuthorizationException(
                'Cannot grant permissions you do not hold: '.implode(', ', $beyond),
            );
        }
    }
}
