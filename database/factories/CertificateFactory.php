<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'certificate_number' => 'OCX-'.fake()->unique()->numberBetween(100000, 999999),
            'verification_code' => Str::lower(Str::random(24)),
            'user_id' => User::factory(),
            'assignment_id' => UserTrainingAssignment::factory(),
            'course_id' => Course::factory(),
            'course_version_id' => CourseVersion::factory()->published(),
            'issued_at' => now()->subDays(3),
            'expires_at' => now()->addYear(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revocation_reason' => 'Issued in error',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
