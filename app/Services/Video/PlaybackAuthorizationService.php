<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Data\Video\PlaybackAuthorization;
use App\Enums\ComplianceEventType;
use App\Exceptions\VideoProviderException;
use App\Models\Lesson;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Mints playback access for one lesson of one assignment.
 *
 * Every grant re-verifies ownership, the frozen course version, the availability window and
 * the assignment status, then records the authorization as evidence. Tokens are short-lived
 * and renewed while the session stays valid. See docs/product-spec.md §12.
 */
class PlaybackAuthorizationService
{
    public function __construct(
        private readonly VideoProvider $videoProvider,
        private readonly ComplianceEventRecorder $events,
    ) {}

    public function authorize(UserTrainingAssignment $assignment, Lesson $lesson): PlaybackAuthorization
    {
        if (! $assignment->includesLesson($lesson)) {
            throw new AuthorizationException('The lesson does not belong to the assigned course version.');
        }

        if (! $assignment->isAvailable()) {
            throw new AuthorizationException('This training is not available yet.');
        }

        if (! $assignment->status->isOpen()) {
            throw new AuthorizationException('This assignment is no longer open.');
        }

        $video = $lesson->video;

        if ($video === null || ! $video->isPlayable()) {
            throw VideoProviderException::notPlayable();
        }

        $authorization = $this->videoProvider->createPlaybackAuthorization(
            $video,
            (int) config('oceanix.playback_token_minutes', 20),
        );

        $this->events->record(
            ComplianceEventType::PlaybackAuthorized,
            $assignment->user_id,
            [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'lesson_id' => $lesson->id,
                'metadata' => ['expires_at' => $authorization->expiresAt->toIso8601String()],
            ],
        );

        return $authorization;
    }
}
