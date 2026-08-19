<?php

namespace Database\Factories;

use App\Enums\TargetScope;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRequirementTarget>
 */
class TrainingRequirementTargetFactory extends Factory
{
    protected $model = TrainingRequirementTarget::class;

    public function definition(): array
    {
        return [
            'training_requirement_id' => TrainingRequirement::factory(),
            'scope_type' => TargetScope::Everyone,
        ];
    }
}
