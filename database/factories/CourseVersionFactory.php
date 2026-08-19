<?php

namespace Database\Factories;

use App\Enums\CourseVersionStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseVersion>
 */
class CourseVersionFactory extends Factory
{
    protected $model = CourseVersion::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'version_number' => 1,
            'status' => CourseVersionStatus::Draft,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(10),
            'completion_rule' => 'all_required_lessons',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseVersionStatus::Published,
            'published_at' => now()->subDays(30),
        ]);
    }
}
