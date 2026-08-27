<?php

namespace Database\Factories;

use App\Enums\ModuleStatus;
use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Module> */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'is_shared' => false,
            'code' => Str::upper(Str::slug($title)),
            'title' => $title,
            'description' => fake()->sentence(10),
            // Compatibility identity row for legacy factory chains. Production code stores
            // the first real module version as version 1 directly in lessons.
            'version_number' => 0,
            'status' => ModuleStatus::Draft,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['company_id' => null, 'is_shared' => true]);
    }
}
