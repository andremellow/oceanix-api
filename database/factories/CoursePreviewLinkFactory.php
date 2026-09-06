<?php

namespace Database\Factories;

use App\Models\CourseVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoursePreviewLinkFactory extends Factory
{
    public function definition(): array
    {
        $token = bin2hex(random_bytes(32));

        return ['course_version_id' => CourseVersion::factory(), 'token_hash' => hash('sha256', $token), 'token_encrypted' => $token, 'generated_at' => now()->startOfSecond(), 'expires_at' => now()->startOfSecond()->addHours(168), 'generated_by_user_id' => User::factory()];
    }
}
