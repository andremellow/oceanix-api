<?php

namespace App\Actions\Platform;

use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

class CreateCompany
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(string $name, ?string $slug = null, ?Account $owner = null): Company
    {
        $previous = $this->context->get();
        $company = Company::query()->create([
            'name' => trim($name),
            'slug' => Str::slug($slug ?: $name),
            'status' => 'active',
        ]);

        try {
            $this->context->set($company);
            (new PermissionSeeder)->run();
            (new RoleSeeder)->run();

            if ($owner !== null) {
                $person = User::query()->create([
                    'account_id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'email_verified_at' => now(),
                    'provider' => $owner->provider,
                    'provider_id' => $owner->provider_id,
                    'workos_user_id' => $owner->workos_user_id,
                    'status' => UserStatus::Active,
                ]);
                $person->roles()->attach(Role::query()->where('key', 'admin')->firstOrFail());
            }
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }

        return $company;
    }
}
