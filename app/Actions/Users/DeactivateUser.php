<?php

namespace App\Actions\Users;

use App\Actions\Users\Concerns\ProtectsAdminAccess;
use App\Exceptions\UserActionDenied;
use App\Models\User;

/**
 * Flip a user's active flag. You can't deactivate yourself, and the last
 * active super admin can't be deactivated (there must always be a way in).
 *
 * The guards themselves now live in {@see ProtectsAdminAccess} so the edit
 * form enforces them too — it used to bypass both (SEC #38).
 *
 * @throws UserActionDenied
 */
class DeactivateUser
{
    use ProtectsAdminAccess;

    public function __invoke(User $user, bool $active, ?int $actingUserId = null): User
    {
        if (! $active) {
            $this->assertMayDeactivate($user, $actingUserId);
        }

        $user->forceFill(['is_active' => $active])->save();

        do_action($active ? 'user.activated' : 'user.deactivated', $user);

        return $user;
    }
}
