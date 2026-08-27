<?php

namespace Database\Factories;

use App\Enums\LessonType;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'company_id' => fn (): int => app(TenantContext::class)->id(),
            'course_version_id' => CourseVersion::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(10),
            'type' => LessonType::Video,
            'position' => 1,
            'is_required' => true,
            'minimum_watch_percentage' => 90,
            'passing_score' => 70,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn (): array => ['is_required' => false]);
    }
}
