<?php

namespace Database\Factories;

use App\Models\JobFunction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobFunction>
 */
class JobFunctionFactory extends Factory
{
    protected $model = JobFunction::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'code' => Str::upper(Str::slug($name)),
            'status' => 'active',
        ];
    }
}
