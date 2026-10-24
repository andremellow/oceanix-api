<?php

namespace App\Models;

class Permission extends \Oceanix\Platform\Models\Permission
{
    public function enum(): ?\App\Enums\Permission
    {
        return \App\Enums\Permission::tryFrom($this->key);
    }
}
