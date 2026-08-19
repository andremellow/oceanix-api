<?php

namespace App\Services\Training;

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\UserTrainingAssignment;

/**
 * Rebuilds the operational progress row from the append-only event trail.
 *
 * Client-reported positions are evidence, not truth. A position that jumps further than
 * wall-clock time allows cannot have been watched, so it is preserved as an event but never
 * credited as progress — otherwise a scripted seek would satisfy the watch threshold.
 * See docs/product-spec.md §7 and §22.
 */
class LessonProgressProjector
{
    /** Allowance for buffering, timer drift and batched reporting. */
    private const DRIFT_TOLERANCE_SECONDS = 30;

    public function project(UserTrainingAssignment $assignment, Lesson $lesson): LessonProgress
    {
        $duration = $lesson->video?->duration_seconds;

        $events = ComplianceEvent::query()
            ->where('assignment_id', $assignment->id)
            ->where('lesson_id', $lesson->id)
            ->whereIn('event_type', [
                ComplianceEventType::VideoPlayed->value,
                ComplianceEventType::VideoProgressed->value,
                ComplianceEventType::VideoPaused->value,
                ComplianceEventType::VideoEnded->value,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $credited = 0;
        $lastPosition = 0;
        $previous = null;

        foreach ($events as $event) {
            $position = (int) ($event->position_seconds ?? 0);

            if ($previous !== null) {
                $elapsed = (int) $previous->received_at->diffInSeconds($event->received_at, absolute: true);
                $advanced = $position - (int) ($previous->position_seconds ?? 0);

                // Credit only playback that could plausibly have happened in real time.
                if ($advanced > 0 && $advanced <= $elapsed + self::DRIFT_TOLERANCE_SECONDS) {
                    $credited += $advanced;
                }
            }

            $lastPosition = $position;
            $previous = $event;
        }

        $percentage = $duration !== null && $duration > 0
            ? (int) min(100, round($credited / $duration * 100))
            : 0;

        $progress = LessonProgress::query()->firstOrNew([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->fill([
            'started_at' => $progress->started_at ?? $events->first()?->occurred_at ?? now(),
            'last_position_seconds' => $lastPosition,
            'watched_seconds' => min($credited, $duration ?? $credited),
            // Progress is a high-water mark: rewatching never lowers it.
            'percentage_watched' => max($progress->percentage_watched ?? 0, $percentage),
        ])->save();

        return $progress->refresh();
    }

    /** A failed lesson must be watched again before the next assessment attempt. */
    public function resetForRewatch(UserTrainingAssignment $assignment, Lesson $lesson): void
    {
        LessonProgress::query()
            ->where('assignment_id', $assignment->id)
            ->where('lesson_id', $lesson->id)
            ->update([
                'percentage_watched' => 0,
                'watched_seconds' => 0,
                'last_position_seconds' => 0,
                'completed_at' => null,
            ]);
    }
}
