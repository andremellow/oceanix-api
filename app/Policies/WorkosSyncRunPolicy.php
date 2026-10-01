<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkosSyncRun;

class WorkosSyncRunPolicy
{
    public function view(User $user, WorkosSyncRun $run): bool
    {
        return $user->company_id === $run->company_id && $user->hasPermission(Permission::PeopleView);
    }
}
