<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TrainingRequirement;
use App\Models\User;

class TrainingRequirementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RequirementsView);
    }

    public function view(User $user, TrainingRequirement $requirement): bool
    {
        return $user->hasPermission(Permission::RequirementsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::RequirementsCreate);
    }

    public function update(User $user, TrainingRequirement $requirement): bool
    {
        return $user->hasPermission(Permission::RequirementsUpdate);
    }

    /** Activating a rule starts materializing real obligations, so it is its own ability. */
    public function activate(User $user, TrainingRequirement $requirement): bool
    {
        return $user->hasPermission(Permission::RequirementsActivate);
    }
}
