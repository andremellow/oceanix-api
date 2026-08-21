<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'provider' => 'workos',
            'provider_id' => 'user_'.Str::random(20),
            'workos_user_id' => 'user_'.Str::random(20),
            'status' => 'active',
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(fn (): array => ['is_platform_admin' => true]);
    }
}
