<?php

namespace Database\Factories;

use App\Enums\AttemptStatus;
use App\Models\CourseAttempt;
use App\Models\CourseVersion;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseAttempt>
 */
class CourseAttemptFactory extends Factory
{
    protected $model = CourseAttempt::class;

    public function definition(): array
    {
        return [
            'assignment_id' => UserTrainingAssignment::factory(),
            'course_version_id' => CourseVersion::factory()->published(),
            'attempt_number' => 1,
            'status' => AttemptStatus::InProgress,
            'started_at' => now()->subHour(),
        ];
    }
}
