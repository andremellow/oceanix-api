<?php

namespace Database\Factories;

use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Models\Course;
use App\Models\TrainingRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRequirement>
 */
class TrainingRequirementFactory extends Factory
{
    protected $model = TrainingRequirement::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->sentence(3),
            'status' => RequirementStatus::Active,
            'frequency_type' => FrequencyType::Months,
            'frequency_value' => 12,
            'renewal_basis' => RenewalBasis::FromCompletion,
            'assignment_lead_days' => 30,
            'due_days_after_assignment' => 30,
        ];
    }

    public function once(): static
    {
        return $this->state(fn (): array => [
            'frequency_type' => FrequencyType::Once,
            'frequency_value' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => RequirementStatus::Draft]);
    }
}
