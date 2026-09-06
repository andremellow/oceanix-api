<?php

namespace App\Services\Courses;

use App\Contracts\VideoProvider;
use App\Enums\CourseVersionStatus;
use App\Enums\PlatformPermission;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PlatformCoursePreview
{
    public function __construct(private readonly PlatformAccess $access, private readonly PublicPreviewResolver $content, private readonly VideoProvider $provider) {}

    public function version(Course $course, CourseVersion $version): CourseVersion
    {
        $account = $this->access->authorizePermission(PlatformPermission::SharedCoursesView);
        abort_unless(Account::query()->whereKey($account->id)->where('status', 'active')->where('is_platform_admin', true)->exists(), 403);
        $course = Course::withoutGlobalScopes()->findOrFail($course->id);
        $version = CourseVersion::query()->findOrFail($version->id);
        abort_unless($course->is_shared && $course->company_id === null && (int) $version->course_id === (int) $course->id, 404);
        abort_if($version->status === CourseVersionStatus::Discarded, 404);

        return $version;
    }

    public function lesson(Course $course, CourseVersion $version, string $kind, string $item): Lesson
    {
        $version = $this->version($course, $version);
        $entry = $this->content->items($version)->first(fn ($entry) => $entry['kind'] === $kind && (string) $entry['id'] === $item);

        return $entry['lesson'] ?? abort(404);
    }

    public function playback(Course $course, CourseVersion $version, string $kind, string $item): array
    {
        $video = $this->content->authoredVideo($this->lesson($course, $version, $kind, $item));
        abort_unless($video?->isPlayable() && $video->provider === $this->provider->key(), 409);
        $expiry = now()->addSeconds(60)->startOfSecond();
        if ($this->provider instanceof FakeVideoProvider) {
            abort_unless(Storage::disk(FakeVideoProvider::DISK)->exists($this->provider->path($video->provider_asset_id)), 409);
            $url = URL::temporarySignedRoute('platform.shared-courses.preview-media', $expiry, [
                'course' => $course->id, 'version' => $version->id, 'kind' => $kind, 'item' => $item, 'asset' => $video->id,
            ]);
            $poster = null;
        } else {
            $grant = $this->provider->createPlaybackAuthorization($video, 1, $expiry);
            abort_unless($grant->expiresAt->lessThanOrEqualTo($expiry), 503);
            $expiry = $grant->expiresAt;
            $url = $grant->playbackUrl;
            $poster = $grant->posterUrl;
        }
        $current = $this->content->authoredVideo($this->lesson($course, $version, $kind, $item));
        abort_unless($current?->isPlayable() && $current->id === $video->id && $current->provider === $video->provider && $current->provider_asset_id === $video->provider_asset_id, 409);
        abort_unless($expiry->isFuture(), 410);

        return ['playback_url' => $url, 'poster_url' => $poster, 'expires_at' => $expiry->toIso8601String()];
    }
}
