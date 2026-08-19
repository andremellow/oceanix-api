<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'provider' => 'cloudflare_stream',
            'provider_asset_id' => Str::random(32),
            'provider_playback_id' => Str::random(32),
            'duration_seconds' => fake()->numberBetween(120, 1800),
            'status' => VideoStatus::Ready,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => VideoStatus::Processing,
            'duration_seconds' => null,
        ]);
    }
}
