<?php

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'code' => Str::upper(Str::slug($title)),
            'title' => $title,
            'description' => fake()->sentence(12),
            'status' => CourseStatus::Active,
            'is_shared' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => CourseStatus::Draft]);
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['company_id' => null, 'is_shared' => true]);
    }
}
