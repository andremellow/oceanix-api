<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,
            'provider' => 'workos',
            'provider_id' => 'user_'.Str::random(20),
            'workos_user_id' => 'user_'.Str::random(20),
            'employee_id' => (string) fake()->unique()->numberBetween(10000, 99999),
            'status' => UserStatus::Active,
            'hired_at' => fake()->dateTimeBetween('-6 years', '-1 month'),
            'remember_token' => Str::random(10),
        ];
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Terminated,
            'terminated_at' => now()->subDays(10),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }
}
