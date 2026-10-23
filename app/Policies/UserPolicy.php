<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('users.view') || $user->hasPermission('users.manage'); }
    public function create(User $user): bool  { return $user->hasPermission('users.manage'); }
    public function update(User $user, User $target): bool { return $user->hasPermission('users.manage'); }

    /** Deactivate / reactivate. A user can never deactivate themselves. */
    public function toggle(User $user, User $target): bool
    {
        return $user->hasPermission('users.manage') && $user->isNot($target);
    }
}
