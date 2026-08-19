<?php

namespace Database\Factories;

use App\Models\LessonAttempt;
use App\Models\Question;
use App\Models\QuestionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionAttempt>
 */
class QuestionAttemptFactory extends Factory
{
    protected $model = QuestionAttempt::class;

    public function definition(): array
    {
        return [
            'lesson_attempt_id' => LessonAttempt::factory(),
            'question_id' => Question::factory(),
            'attempt_number' => 1,
            'selected_option_ids' => [],
            'is_correct' => false,
            'answered_at' => now(),
        ];
    }
}
