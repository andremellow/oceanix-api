<?php

namespace App\Actions\People;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;

class AssignAccessProfile
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function toggle(User $person, Role $role): void
    {
        $before = $person->roles()->pluck('key')->all();
        $person->roles()->toggle($role);

        $this->audit->log(
            'person.access_profiles_changed',
            $person,
            ['roles' => $before],
            ['roles' => $person->roles()->pluck('key')->all()],
        );
    }
}
