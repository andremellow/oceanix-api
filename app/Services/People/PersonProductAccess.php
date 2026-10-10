<?php

namespace App\Services\People;

use App\Models\PersonProductAccess as Projection;
use App\Models\User;

class PersonProductAccess
{
    public function allows(User $person): bool
    {
        // Unlinked pre-cutover people retain baseline access. A delivered block has no role bypass.
        $projection = Projection::query()->where('company_id', $person->company_id)->where('user_id', $person->id)->first();

        return $projection === null || $projection->confirmed_enabled;
    }
}
