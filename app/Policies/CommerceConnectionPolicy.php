<?php

namespace App\Policies;

use App\Models\CommerceConnection;
use App\Models\User;

class CommerceConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('commerce.view');
    }

    public function view(User $user, CommerceConnection $connection): bool
    {
        return $user->hasPermission('commerce.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('commerce.manage');
    }

    public function update(User $user, CommerceConnection $connection): bool
    {
        return $user->hasPermission('commerce.manage');
    }

    public function delete(User $user, CommerceConnection $connection): bool
    {
        return $user->hasPermission('commerce.manage');
    }
}
