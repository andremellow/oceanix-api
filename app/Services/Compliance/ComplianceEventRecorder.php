<?php

namespace App\Services\Compliance;

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The only supported way to write the append-only trail.
 *
 * Ingestion is idempotent on the client-generated UUID: replaying a sync batch returns the
 * stored event instead of inserting a second one. `occurred_at` is what the device claims;
 * `received_at` is the server's own clock and is never taken from the client — that split is
 * what makes the future offline mode reconcilable. See docs/product-spec.md §11.
 *
 * @phpstan-type EventAttributes array{
 *     uuid?: string, assignment_id?: int|null, course_version_id?: int|null, lesson_id?: int|null,
 *     course_attempt_id?: int|null, lesson_attempt_id?: int|null, question_id?: int|null,
 *     device_id?: string|null, session_id?: string|null, occurred_at?: Carbon|string|null,
 *     client_sequence?: int|null, position_seconds?: int|null, metadata?: array<string, mixed>|null,
 *     ip_address?: string|null, user_agent?: string|null
 * }
 */
class ComplianceEventRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(ComplianceEventType $type, int $userId, array $attributes = []): ComplianceEvent
    {
        $uuid = (string) ($attributes['uuid'] ?? Str::uuid());
        $existing = ComplianceEvent::query()->where('uuid', $uuid)->first();

        if ($existing !== null) {
            return $existing;
        }

        $occurredAt = $attributes['occurred_at'] ?? null;

        return ComplianceEvent::query()->create([
            'uuid' => $uuid,
            'event_type' => $type,
            'user_id' => $userId,
            'assignment_id' => $attributes['assignment_id'] ?? null,
            'course_version_id' => $attributes['course_version_id'] ?? null,
            'lesson_id' => $attributes['lesson_id'] ?? null,
            'course_attempt_id' => $attributes['course_attempt_id'] ?? null,
            'lesson_attempt_id' => $attributes['lesson_attempt_id'] ?? null,
            'question_id' => $attributes['question_id'] ?? null,
            'device_id' => $attributes['device_id'] ?? null,
            'session_id' => $attributes['session_id'] ?? session()->getId(),
            'occurred_at' => $occurredAt !== null ? Carbon::parse($occurredAt) : now(),
            // Server clock only — a wrong device clock must never move the received time.
            'received_at' => now(),
            'client_sequence' => $attributes['client_sequence'] ?? null,
            'position_seconds' => $attributes['position_seconds'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'ip_address' => $attributes['ip_address'] ?? request()->ip(),
            'user_agent' => $attributes['user_agent'] ?? substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    /**
     * Ingest a batch of client-reported events. Duplicated UUIDs resolve to the stored row,
     * so an interrupted sync can safely resend the whole batch.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<ComplianceEvent>
     */
    public function ingestBatch(int $userId, array $events): array
    {
        $recorded = [];

        foreach ($events as $event) {
            $type = ComplianceEventType::tryFrom((string) ($event['event_type'] ?? ''));

            // Server-authored event types (completions, certificates) are never accepted
            // from a client: evidence the client could forge would not be evidence.
            if ($type === null || ! $type->isClientReportable()) {
                continue;
            }

            $recorded[] = $this->record($type, $userId, $event);
        }

        return $recorded;
    }
}
