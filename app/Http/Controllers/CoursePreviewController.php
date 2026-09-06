<?php

namespace App\Http\Controllers;

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Contracts\VideoProvider;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Courses\CoursePreviewProjection;
use App\Services\Courses\PreviewPlaybackService;
use App\Services\Courses\PublicPreviewResolver;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\FakeVideoProvider;
use App\Services\Video\LocalVideoStream;
use Illuminate\Http\Request;

class CoursePreviewController extends Controller
{
    public function operator(Request $request, GenerateCoursePreviewLink $action)
    {
        $course = Course::withoutGlobalScopes()->findOrFail($request->route('course'));
        $version = CourseVersion::query()->findOrFail($request->route('version'));
        $actor = $request->routeIs('platform.*') ? app(PlatformAccess::class)->authorize() : $request->user();
        $result = $request->isMethod('post') ? $action->generate($course, $version, $actor) : ['link' => $action->retrieve($course, $version, $actor), 'created' => false];

        return response()->json($result['link'], $result['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function show(#[\SensitiveParameter] string $token, PublicPreviewResolver $resolver, CoursePreviewProjection $projection)
    {
        return view('course-preview.reader', ['preview' => $projection->project($resolver->resolve($token)), 'token' => $token, 'kind' => null, 'item' => null]);
    }

    public function item(#[\SensitiveParameter] string $token, string $kind, string $item, PublicPreviewResolver $resolver, CoursePreviewProjection $projection)
    {
        $link = $resolver->resolve($token);

        return view('course-preview.reader', ['preview' => $projection->project($link, $resolver->item($link, $kind, $item)), 'token' => $token, 'kind' => $kind, 'item' => $item]);
    }

    public function playback(#[\SensitiveParameter] string $token, string $kind, string $item, PreviewPlaybackService $playback)
    {
        return response()->json($playback->authorize($token, $kind, $item));
    }

    public function media(Request $request, #[\SensitiveParameter] string $token, string $kind, string $item, string $asset, PublicPreviewResolver $resolver, VideoProvider $provider, LocalVideoStream $stream)
    {
        abort_unless($provider instanceof FakeVideoProvider && app()->environment(['local', 'testing']), 404);
        abort_unless($request->hasValidSignature() && (int) $request->query('expires') > now()->timestamp, 403);
        $video = $resolver->authoredVideo($resolver->item($resolver->resolve($token), $kind, $item));
        abort_unless($video?->isPlayable() && (string) $video->id === $asset && $video->provider === $provider->key(), 404);

        return $stream->response($video->provider_asset_id, $provider);
    }

    public function locale(Request $request, #[\SensitiveParameter] string $token, string $locale)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) && in_array($locale, ['en', 'pt_BR'], true), 404);
        $request->session()->put('preview_locale', $locale);

        return redirect()->route('course-preview.show', ['token' => $token]);
    }
}
