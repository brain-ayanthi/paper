<?php

namespace App\Actions\Users;

use App\Actions\Users\Concerns\ProtectsAdminAccess;
use App\Exceptions\UserActionDenied;
use App\Models\User;

/**
 * Update a user's profile + status, optionally their password (blank =
 * unchanged) and super-admin flag. Demoting the last active super admin
 * is refused. Store/role assignment is handled separately.
 *
 * The edit form used to be a way AROUND the deactivation guards — it filled
 * `is_active` straight from the request, so an admin could switch themselves
 * off and the last active super admin could be deactivated here even though
 * DeactivateUser refused it (SEC #38). Both now run on this path too.
 *
 * Hooks: `user.before_update` ($user, $data) · `user.after_update` ($user).
 *
 * @throws UserActionDenied
 */
class UpdateUser
{
    use ProtectsAdminAccess;

    /** @param array<string, mixed> $data */
    public function __invoke(
        User $user,
        array $data,
        ?string $password = null,
        ?bool $isSuperAdmin = null,
        ?int $actingUserId = null,
    ): User {
        if ($isSuperAdmin === false) {
            $this->assertMayDemoteSuperAdmin($user);
        }

        // Same rules the dedicated deactivate action applies — only checked
        // when the flag actually changes to off, so re-saving an already
        // inactive user is not blocked.
        $becomingInactive = array_key_exists('is_active', $data)
            && ! $data['is_active']
            && $user->is_active;

        if ($becomingInactive) {
            $this->assertMayDeactivate($user, $actingUserId);
        }

        do_action('user.before_update', $user, $data);

        $user->fill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'locale'    => $data['locale'] ?? $user->locale,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);

        if ($password !== null && $password !== '') {
            $user->password = $password;
        }

        if ($isSuperAdmin !== null) {
            $user->is_super_admin = $isSuperAdmin;
        }

        $user->save();

        do_action('user.after_update', $user);

        return $user;
    }
}
