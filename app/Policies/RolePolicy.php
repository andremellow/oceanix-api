<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Access profiles are administrator-only: Gate::before grants admins everything, so every
 * ability here returns false for anyone else.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Role $role): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return false;
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_protected;
    }
}
