<?php

namespace App\Services\Compliance;

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use App\Models\CourseAttempt;
use App\Models\Lesson;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Collection;

/**
 * Reconstructs what actually happened on an assignment, for the people who have to answer
 * for it. Everything here is derived from the append-only trail — nothing is read from a
 * projection — so what an auditor sees is the evidence itself.
 * See docs/product-spec.md §11 and §15.
 */
class AssignmentEvidence
{
    /** Resolution of the coverage map. Smaller buckets than the checkpoint interval would
     * imply a precision the events do not have. */
    private const BUCKET_SECONDS = 15;

    /** Matches the projector, so the picture and the credited number agree. */
    private const DRIFT_TOLERANCE_SECONDS = 30;

    /**
     * How many times each part of a lesson's video was watched.
     *
     * @return array{duration: int, covered: int, percentage: int, buckets: list<array{from: int, to: int, times: int}>}
     */
    public function watchMap(UserTrainingAssignment $assignment, Lesson $lesson): array
    {
        $duration = $lesson->video?->duration_seconds ?? 0;

        if ($duration === 0) {
            return ['duration' => 0, 'covered' => 0, 'percentage' => 0, 'buckets' => []];
        }

        $bucketCount = (int) ceil($duration / self::BUCKET_SECONDS);
        $hits = array_fill(0, $bucketCount, 0);

        foreach ($this->watchPasses($assignment, $lesson) as [$from, $to]) {
            $first = intdiv($from, self::BUCKET_SECONDS);
            $last = min($bucketCount - 1, intdiv(max($to - 1, 0), self::BUCKET_SECONDS));

            for ($bucket = $first; $bucket <= $last; $bucket++) {
                $hits[$bucket]++;
            }
        }

        $covered = count(array_filter($hits)) * self::BUCKET_SECONDS;

        return [
            'duration' => $duration,
            'covered' => min($covered, $duration),
            'percentage' => (int) min(100, round($covered / $duration * 100)),
            'buckets' => collect($hits)
                ->map(fn (int $times, int $index): array => [
                    'from' => $index * self::BUCKET_SECONDS,
                    'to' => min(($index + 1) * self::BUCKET_SECONDS, $duration),
                    'times' => $times,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Continuous stretches of playback.
     *
     * Consecutive checkpoints describe one uninterrupted watch, so they are joined before
     * anything is counted — otherwise a bucket sitting on the boundary between two
     * checkpoints would be reported as watched twice when it was watched once.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function watchPasses(UserTrainingAssignment $assignment, Lesson $lesson): array
    {
        $passes = [];
        $current = null;
        $previous = null;

        foreach ($this->playbackEvents($assignment, $lesson) as $event) {
            $position = (int) ($event->position_seconds ?? 0);

            if ($previous !== null) {
                $from = (int) ($previous->position_seconds ?? 0);
                $elapsed = (int) $previous->received_at->diffInSeconds($event->received_at, absolute: true);
                $advanced = $position - $from;

                // The same plausibility rule the projector applies, so the picture an
                // auditor sees matches the number the threshold was judged on.
                if ($advanced > 0 && $advanced <= $elapsed + self::DRIFT_TOLERANCE_SECONDS) {
                    if ($current !== null && $from <= $current[1]) {
                        $current[1] = max($current[1], $position);
                    } else {
                        if ($current !== null) {
                            $passes[] = $current;
                        }

                        $current = [$from, $position];
                    }
                } elseif ($current !== null) {
                    $passes[] = $current;
                    $current = null;
                }
            }

            $previous = $event;
        }

        if ($current !== null) {
            $passes[] = $current;
        }

        return $passes;
    }

    /**
     * @return Collection<int, CourseAttempt>
     */
    public function attempts(UserTrainingAssignment $assignment): Collection
    {
        return $assignment->courseAttempts()
            ->with(['lessonAttempts.lesson', 'lessonAttempts.questionAttempts.question.options'])
            ->orderByDesc('attempt_number')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceEvent>
     */
    public function timeline(UserTrainingAssignment $assignment, int $limit = 200): Collection
    {
        return $assignment->complianceEvents()
            ->with('lesson:id,title')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Devices that reported events, which is what a shared-device question comes down to. */
    public function devices(UserTrainingAssignment $assignment): Collection
    {
        return $assignment->complianceEvents()
            ->selectRaw('coalesce(device_id, session_id) as device, count(*) as events, min(occurred_at) as first_seen, max(occurred_at) as last_seen')
            ->groupByRaw('coalesce(device_id, session_id)')
            ->orderByDesc('events')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceEvent>
     */
    private function playbackEvents(UserTrainingAssignment $assignment, Lesson $lesson): Collection
    {
        return ComplianceEvent::query()
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
    }
}
