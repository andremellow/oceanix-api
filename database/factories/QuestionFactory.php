<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Lesson;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'type' => QuestionType::SingleChoice,
            'prompt' => fake()->sentence(8).'?',
            'position' => 1,
            'max_attempts' => 3,
            'weight' => 1,
        ];
    }

    public function multipleChoice(): static
    {
        return $this->state(fn (): array => ['type' => QuestionType::MultipleChoice]);
    }
}
