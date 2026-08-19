<?php

namespace Database\Factories;

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ComplianceEvent>
 */
class ComplianceEventFactory extends Factory
{
    protected $model = ComplianceEvent::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'event_type' => ComplianceEventType::AssignmentOpened,
            'user_id' => User::factory(),
            'session_id' => Str::random(20),
            'occurred_at' => now(),
            'received_at' => now(),
        ];
    }
}
