<?php

use App\Contracts\VideoProvider;
use App\Data\Video\DownloadAuthorization;
use App\Data\Video\PlaybackAuthorization;
use App\Data\Video\VideoAssetStatus;
use App\Data\Video\VideoUpload;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\ComplianceEvent;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\Video;
use App\Services\Video\PlaybackAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/** A stand-in provider: the domain only knows the contract, never the vendor. */
function fakeVideoProvider(): void
{
    app()->bind(VideoProvider::class, fn (): VideoProvider => new class implements VideoProvider
    {
        public function key(): string
        {
            return 'fake';
        }

        public function verifyConfiguration(bool $write = true): array
        {
            return [['label' => 'Test double', 'ok' => true, 'detail' => null]];
        }

        public function createUpload(string $title, int $maxDurationSeconds, string $ownerKey): VideoUpload
        {
            return new VideoUpload('fake', 'asset', 'https://upload.test');
        }

        public function listAssets(int $limit = 12, string $search = '', string $ownerKey = ''): array
        {
            return [];
        }

        public function createAssetPreviewAuthorization(string $assetId, ?string $hlsUrl, int $ttlMinutes): PlaybackAuthorization
        {
            return new PlaybackAuthorization('preview-token', 'https://play.test/preview.m3u8', now()->addMinutes($ttlMinutes));
        }

        public function getAssetStatus(string $assetId): VideoAssetStatus
        {
            return new VideoAssetStatus(VideoStatus::Ready);
        }

        public function createPlaybackAuthorization(Video $video, int $ttlMinutes, ?Carbon $absoluteExpiresAt = null): PlaybackAuthorization
        {
            return new PlaybackAuthorization('signed-token', 'https://play.test/x.m3u8', now()->addMinutes($ttlMinutes));
        }

        public function createDownloadAuthorization(Video $video, int $ttlMinutes): DownloadAuthorization
        {
            return new DownloadAuthorization('https://download.test', now()->addMinutes($ttlMinutes));
        }

        public function deleteAsset(string $assetId): void {}
    });
}

function playableAssignment(array $attributes = []): array
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);
    Video::factory()->create(['lesson_id' => $lesson->id]);

    $assignment = UserTrainingAssignment::factory()->create([
        'user_id' => User::factory(),
        'course_id' => $course->id,
        'course_version_id' => $version->id,
        ...$attributes,
    ]);

    return [$assignment->fresh(), $lesson->fresh()];
}

beforeEach(fn () => fakeVideoProvider());

it('mints a short-lived token and records the authorization as evidence', function (): void {
    [$assignment, $lesson] = playableAssignment();

    $authorization = app(PlaybackAuthorizationService::class)->authorize($assignment, $lesson);

    expect($authorization->token)->toBe('signed-token')
        ->and($authorization->expiresAt->isFuture())->toBeTrue()
        ->and(ComplianceEvent::query()
            ->where('event_type', ComplianceEventType::PlaybackAuthorized->value)
            ->where('assignment_id', $assignment->id)
            ->exists())->toBeTrue();
});

it('refuses a lesson that belongs to another course version', function (): void {
    [$assignment] = playableAssignment();
    $otherLesson = Lesson::factory()->create();

    expect(fn () => app(PlaybackAuthorizationService::class)->authorize($assignment, $otherLesson))
        ->toThrow(AuthorizationException::class);
});

it('refuses playback before the assignment becomes available', function (): void {
    [$assignment, $lesson] = playableAssignment(['available_at' => now()->addWeek()]);

    expect(fn () => app(PlaybackAuthorizationService::class)->authorize($assignment, $lesson))
        ->toThrow(AuthorizationException::class);
});

it('refuses playback once the assignment is closed', function (): void {
    [$assignment, $lesson] = playableAssignment(['status' => AssignmentStatus::Cancelled]);

    expect(fn () => app(PlaybackAuthorizationService::class)->authorize($assignment, $lesson))
        ->toThrow(AuthorizationException::class);
});

it('refuses playback while the video is still processing', function (): void {
    [$assignment, $lesson] = playableAssignment();
    $lesson->video->update(['status' => VideoStatus::Processing]);

    expect(fn () => app(PlaybackAuthorizationService::class)->authorize($assignment, $lesson->fresh()))
        ->toThrow(VideoProviderException::class);
});
