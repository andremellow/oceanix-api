<?php

namespace Database\Factories;

use App\Enums\ModuleVersionStatus;
use App\Models\Module;
use App\Models\ModuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModuleVersion> */
class ModuleVersionFactory extends Factory
{
    protected $model = ModuleVersion::class;

    public function definition(): array
    {
        return [
            'module_id' => Module::factory(),
            'version_number' => 1,
            'status' => ModuleVersionStatus::Draft,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(10),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ModuleVersionStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function shared(): static
    {
        return $this->for(Module::factory()->shared());
    }
}
