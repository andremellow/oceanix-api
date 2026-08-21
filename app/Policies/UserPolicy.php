<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PeopleView);
    }

    public function view(User $user, User $subject): bool
    {
        return $user->id === $subject->id || $user->hasPermission(Permission::PeopleView);
    }

    public function manage(User $user, User $subject): bool
    {
        return $user->hasPermission(Permission::PeopleManage);
    }

    /** Role assignment stays administrator-only, above the people permissions. */
    public function assignRoles(User $user, User $subject): bool
    {
        return $user->hasPermission(Permission::PeopleAssignAccessProfiles);
    }

    public function invite(User $user, User $subject): bool
    {
        return $user->hasPermission(Permission::PeopleInvite);
    }
}
