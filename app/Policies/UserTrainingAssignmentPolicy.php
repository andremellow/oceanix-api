<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserTrainingAssignment;

class UserTrainingAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AssignmentsView);
    }

    /** A person always sees their own obligation; anyone else needs the permission. */
    public function view(User $user, UserTrainingAssignment $assignment): bool
    {
        return $assignment->user_id === $user->id
            || $user->hasPermission(Permission::AssignmentsView);
    }

    /** Only the assignee can execute the training — never an administrator on their behalf. */
    public function execute(User $user, UserTrainingAssignment $assignment): bool
    {
        return $assignment->user_id === $user->id
            && $assignment->status->isOpen()
            && $assignment->isAvailable();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::AssignmentsCreate);
    }

    public function cancel(User $user, UserTrainingAssignment $assignment): bool
    {
        return $assignment->status->isOpen()
            && $user->hasPermission(Permission::AssignmentsCancel);
    }

    public function waive(User $user, UserTrainingAssignment $assignment): bool
    {
        return $assignment->status->isOpen()
            && $user->hasPermission(Permission::AssignmentsWaive);
    }
}
