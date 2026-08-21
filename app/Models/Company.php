<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'name', 'slug', 'workos_organization_id', 'status'])]
class Company extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Company $company) => $company->public_id ??= (string) Str::uuid());
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
