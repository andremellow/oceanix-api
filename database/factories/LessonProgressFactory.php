<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    public function definition(): array
    {
        return [
            'assignment_id' => UserTrainingAssignment::factory(),
            'lesson_id' => Lesson::factory(),
            'started_at' => now()->subHour(),
            'last_position_seconds' => 0,
            'watched_seconds' => 0,
            'percentage_watched' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'percentage_watched' => 100,
            'completed_at' => now(),
        ]);
    }
}
