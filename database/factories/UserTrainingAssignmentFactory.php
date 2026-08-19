<?php

namespace Database\Factories;

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTrainingAssignment>
 */
class UserTrainingAssignmentFactory extends Factory
{
    protected $model = UserTrainingAssignment::class;

    public function definition(): array
    {
        $version = CourseVersion::factory()->published();

        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'course_version_id' => $version,
            'origin_type' => AssignmentOrigin::Manual,
            'assigned_at' => now()->subDays(10),
            'due_at' => now()->addDays(20),
            'status' => AssignmentStatus::Pending,
        ];
    }

    /**
     * Attach the assignment to an existing course, keeping course_id and
     * course_version_id consistent — a mismatched pair would be invalid evidence.
     */
    public function forCourse(Course $course): static
    {
        return $this->state(fn (): array => [
            'course_id' => $course->id,
            'course_version_id' => $course->current_published_version_id
                ?? CourseVersion::factory()->published()->create(['course_id' => $course->id])->id,
        ]);
    }

    public function overdue(int $days = 5): static
    {
        return $this->state(fn (): array => [
            'due_at' => now()->subDays($days),
            'status' => AssignmentStatus::Overdue,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => AssignmentStatus::Completed,
            'completed_at' => now()->subDays(2),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => ['status' => AssignmentStatus::InProgress]);
    }
}
