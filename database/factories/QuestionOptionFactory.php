<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    public function definition(): array
    {
        return [
            'company_id' => fn (): int => app(TenantContext::class)->id(),
            'question_id' => Question::factory(),
            'text' => fake()->sentence(5),
            'is_correct' => false,
            'position' => 1,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (): array => ['is_correct' => true]);
    }
}
