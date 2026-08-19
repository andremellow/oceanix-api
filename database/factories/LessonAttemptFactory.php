<?php

namespace Database\Factories;

use App\Enums\AttemptStatus;
use App\Models\CourseAttempt;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonAttempt>
 */
class LessonAttemptFactory extends Factory
{
    protected $model = LessonAttempt::class;

    public function definition(): array
    {
        return [
            'course_attempt_id' => CourseAttempt::factory(),
            'lesson_id' => Lesson::factory(),
            'attempt_number' => 1,
            'status' => AttemptStatus::InProgress,
            'started_at' => now()->subMinutes(30),
        ];
    }
}
