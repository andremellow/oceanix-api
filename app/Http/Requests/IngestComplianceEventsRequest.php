<?php

namespace App\Http\Requests;

use App\Enums\ComplianceEventType;
use App\Models\Lesson;
use App\Models\UserTrainingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngestComplianceEventsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'max:100'],
            'events.*.uuid' => ['required', 'uuid'],
            // Server-authored types are rejected outright: a client cannot declare a course
            // complete or a certificate issued.
            'events.*.event_type' => ['required', Rule::in(ComplianceEventType::clientReportableValues())],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'events.*.client_sequence' => ['nullable', 'integer', 'min:0'],
            'events.*.session_id' => ['nullable', 'string', 'max:100'],
            'events.*.device_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Bind every event to this assignment and lesson, whatever the payload claimed.
     *
     * @return list<array<string, mixed>>
     */
    public function events(UserTrainingAssignment $assignment, Lesson $lesson): array
    {
        return collect($this->validated('events'))
            ->map(fn (array $event): array => [
                ...$event,
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'lesson_id' => $lesson->id,
            ])
            ->all();
    }
}
