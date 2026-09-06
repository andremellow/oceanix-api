<?php

namespace App\Http\Controllers;

use App\Contracts\VideoProvider;
use App\Exceptions\VideoProviderException;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Courses\PlatformCoursePreview;
use App\Services\Courses\PublicPreviewResolver;
use App\Services\Video\FakeVideoProvider;
use App\Services\Video\LocalVideoStream;
use Illuminate\Http\Request;

class PlatformCoursePreviewController extends Controller
{
    public function show(Request $request, Course $course, CourseVersion $version, PlatformCoursePreview $preview, PublicPreviewResolver $content)
    {
        $version = $preview->version($course, $version);
        $kind = $request->query('kind');
        $item = $request->query('item');
        abort_unless(($kind === null && $item === null) || (is_string($kind) && is_string($item)), 404);
        $lesson = $kind === null ? null : $preview->lesson($course, $version, $kind, $item);

        return response()->view('platform.course-preview', [
            'course' => $course, 'version' => $version, 'items' => $content->items($version),
            'lesson' => $lesson, 'kind' => $kind, 'item' => $item,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function playback(Course $course, CourseVersion $version, string $kind, string $item, PlatformCoursePreview $preview)
    {
        try {
            return response()->json($preview->playback($course, $version, $kind, $item))->header('Cache-Control', 'private, no-store');
        } catch (VideoProviderException) {
            return response()->json(['message' => __('Video unavailable. Please try again.')], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function media(Request $request, Course $course, CourseVersion $version, string $kind, string $item, string $asset, PlatformCoursePreview $preview, PublicPreviewResolver $content, VideoProvider $provider, LocalVideoStream $stream)
    {
        abort_unless($provider instanceof FakeVideoProvider && app()->environment(['local', 'testing']), 404);
        abort_unless($request->hasValidSignature() && (int) $request->query('expires') > now()->timestamp, 403);
        $video = $content->authoredVideo($preview->lesson($course, $version, $kind, $item));
        abort_unless($video?->isPlayable() && (string) $video->id === $asset && $video->provider === $provider->key(), 404);

        return $stream->response($video->provider_asset_id, $provider)->setPrivate()->setMaxAge(0);
    }
}
