<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'email', 'provider', 'provider_id', 'workos_user_id', 'avatar_url',
    'is_platform_admin', 'status',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_platform_admin' => 'boolean'];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => strtolower(trim($value)));
    }

    /** @return HasMany<User, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
