<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Database projection of App\Enums\Permission. The enum is the catalog of record; this
 * table exists so access profiles can reference permissions relationally.
 */
#[Fillable(['key', 'label', 'group'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function enum(): ?PermissionEnum
    {
        return PermissionEnum::tryFrom($this->key);
    }
}
