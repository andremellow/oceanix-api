<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngestComplianceEventsRequest;
use App\Models\Company;
use App\Models\Lesson;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Training\LessonProgressProjector;
use App\Services\Video\PlaybackAuthorizationService;
use Illuminate\Http\JsonResponse;

/**
 * Playback authorization and event ingestion for the lesson player.
 *
 * Both endpoints are per-assignment and re-authorize on every call, so a token or an event
 * batch can never be replayed against someone else's obligation.
 */
class TrainingPlaybackController extends Controller
{
    public function authorizePlayback(
        Company $company,
        UserTrainingAssignment $assignment,
        Lesson $lesson,
        PlaybackAuthorizationService $playback,
    ): JsonResponse {
        $this->authorize('execute', $assignment);

        $authorization = $playback->authorize($assignment, $lesson);

        return response()->json([
            'url' => $authorization->playbackUrl,
            'poster' => $authorization->posterUrl,
            'expires_in' => $authorization->secondsRemaining(),
        ]);
    }

    public function ingest(
        IngestComplianceEventsRequest $request,
        Company $company,
        UserTrainingAssignment $assignment,
        Lesson $lesson,
        ComplianceEventRecorder $recorder,
        LessonProgressProjector $projector,
    ): JsonResponse {
        $this->authorize('execute', $assignment);

        abort_unless($assignment->includesLesson($lesson), 404);

        $recorder->ingestBatch($assignment->user_id, $request->events($assignment, $lesson));

        $progress = $projector->project($assignment, $lesson);

        return response()->json([
            'percentage_watched' => $progress->percentage_watched,
            'watch_threshold_met' => $progress->percentage_watched >= $lesson->minimum_watch_percentage,
            'assessment_unlocked' => true,
        ]);
    }
}
