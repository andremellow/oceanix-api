<?php

namespace Database\Factories;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oceanix\Platform\Models\Company;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function configure(): static
    {
        return $this->afterCreating(fn (Company $company) => app(TenantContext::class)->set($company));
    }

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999),
            'status' => 'active',
        ];
    }
}
