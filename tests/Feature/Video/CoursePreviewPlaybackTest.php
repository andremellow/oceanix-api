<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Contracts\VideoProvider;
use App\Data\Video\PlaybackAuthorization;
use App\Enums\ComplianceEventType;
use App\Http\Controllers\DevVideoController;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\CourseAttempt;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonProgress;
use App\Models\QuestionAttempt;
use App\Models\Video;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Video\CloudflareStreamProvider;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function previewVideoGraph(string $provider = 'cloudflare_stream'): array
{
    $version = CourseVersion::factory()->create();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id, 'content_markdown' => "Before video\n\n:::video\n\nAfter video"]);
    $video = Video::factory()->create(['lesson_id' => $lesson->id, 'provider' => $provider]);
    $link = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $item = CourseVersionModule::where('lesson_id', $lesson->id)->first();
    $url = route('course-preview.playback', ['token' => basename($link['url']), 'kind' => 'composition', 'item' => $item->id]);

    return [$version, $lesson, $video, $link, $url];
}
it('bounds cloudflare playback and poster grants to sixty seconds and link expiry without evidence', function () {
    $this->travelTo(now()->startOfSecond());
    app()->bind(VideoProvider::class, CloudflareStreamProvider::class);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $this->travelTo(CoursePreviewLink::first()->expires_at->subSeconds(20));
    $expiry = now()->addSeconds(20)->timestamp;
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['token' => 'bounded-token']])]);
    $data = $this->postJson($url)->assertOk()->json();
    expect(strtotime($data['expires_at']))->toBe($expiry);
    expect($data['poster_url'])->toContain('bounded-token');
    expect($data)->not->toHaveKeys(['token', 'provider_asset_id', 'download_url']);
    Http::assertSent(fn ($request) => $request['exp'] === $expiry);
    foreach (['user_training_assignments', 'lesson_progress', 'question_attempts', 'compliance_events', 'certificates'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});
it('serves actual local signed bytes and Range independently of an inactive company and refuses asset tampering', function () {
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph('local_fake');
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, '0123456789abcdef');
    $inactive = Company::factory()->create(['status' => 'inactive']);
    $grant = $this->withSession(['company_id' => $inactive->id])->postJson($url)->assertOk()->json();
    expect($grant['playback_url'])->toContain('/preview/courses/')->not->toContain('/dev/videos/');
    $response = $this->get($grant['playback_url'])->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('0123456789abcdef');
    $this->get($grant['playback_url'], ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/16')->assertHeader('Content-Length', '4');
    $this->get(str_replace('/media/'.$video->id.'?', '/media/999999?', $grant['playback_url']))->assertForbidden();
    $video->update(['is_current' => false]);
    $this->get($grant['playback_url'])->assertNotFound();
});
it('refuses a ready local video with no file before granting playback and permits restored media', function () {
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph('local_fake');
    expect($video->isPlayable())->toBeTrue();
    $response = $this->postJson($url)->assertStatus(409)->assertJsonPath('error', 'media_unavailable');
    expect($response->json())->not->toHaveKeys(['playback_url', 'expires_at', 'poster_url', 'token']);
    $response->assertDontSee(basename($link['url']))->assertDontSee($video->provider_asset_id)->assertDontSee('signature=');

    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, 'restored-media');
    $grant = $this->postJson($url)->assertOk()->json();
    $media = $this->get($grant['playback_url'], ['Range' => 'bytes=0-7'])->assertStatus(206);
    ob_start();
    $media->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('restored');
});
it('denies processing video and provider failures with generic bounded error responses', function () {
    app()->bind(VideoProvider::class, CloudflareStreamProvider::class);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $video->update(['status' => 'processing']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)]);
    $this->postJson($url)->assertStatus(409)->assertJsonPath('error', 'media_unavailable');
    Http::assertNothingSent();
    $video->update(['status' => 'ready']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)]);
    $this->postJson($url)->assertStatus(503)->assertJsonPath('error', 'temporarily_unavailable')->assertDontSee($video->provider_asset_id);
});
it('rechecks capability and current video after provider latency', function (string $change) {
    app()->bind(VideoProvider::class, CloudflareStreamProvider::class);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    Http::fake(function () use ($change, $version, $video) {
        match ($change) {
            'expired' => $this->travelTo(CoursePreviewLink::first()->expires_at),
            'published' => $version->update(['status' => 'published']),
            'replaced' => $video->update(['is_current' => false]),
            'removed' => $version->moduleCompositions()->delete(),
        };

        return Http::response(['success' => true, 'result' => ['token' => 'temporary-token']]);
    });
    $this->postJson($url)->assertStatus(match ($change) {
        'expired', 'published' => 410, 'replaced' => 409, 'removed' => 404
    });
})->with(['expired', 'published', 'replaced', 'removed']);
it('denies local media at the exact grant expiry and immediately after publication', function () {
    $this->travelTo(now()->startOfSecond());
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph('local_fake');
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, 'bytes');
    $grant = $this->postJson($url)->assertOk()->json();
    $this->travelTo(Carbon::parse($grant['expires_at']));
    $this->get($grant['playback_url'])->assertForbidden();
    $grant = $this->postJson($url)->assertOk()->json();
    $version->update(['status' => 'published']);
    $this->get($grant['playback_url'])->assertGone();
    $this->postJson($url)->assertGone();
});

it('fails closed if a provider returns an overlong grant', function () {
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('key')->andReturn('cloudflare_stream');
    $provider->shouldReceive('createPlaybackAuthorization')->once()->andReturn(new PlaybackAuthorization('bad', 'https://video.example/bad.m3u8', now()->addMinutes(2)));
    app()->instance(VideoProvider::class, $provider);
    $this->postJson($url)->assertStatus(503)->assertDontSee('https://video.example/bad.m3u8');
});

it('rejects another real video id even with a valid preview-media signature', function () {
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link] = previewVideoGraph('local_fake');
    $foreign = Video::factory()->create(['provider' => 'local_fake']);
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, 'selected-video-payload');
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$foreign->provider_asset_id, 'foreign-video-payload');
    $item = CourseVersionModule::where('lesson_id', $lesson->id)->first();
    $selectedUrl = URL::temporarySignedRoute('course-preview.local-media', now()->addMinute(), ['token' => basename($link['url']), 'kind' => 'composition', 'item' => $item->id, 'asset' => $video->id]);
    $selected = $this->get($selectedUrl)->assertOk();
    ob_start();
    $selected->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('selected-video-payload');
    $url = URL::temporarySignedRoute('course-preview.local-media', now()->addMinute(), ['token' => basename($link['url']), 'kind' => 'composition', 'item' => $item->id, 'asset' => $foreign->id]);
    $this->get($url)->assertNotFound()->assertDontSee($foreign->provider_asset_id)->assertDontSee('selected-video-payload')->assertDontSee('foreign-video-payload');
});

it('preserves every populated evidence row while a signed-in learner browses and plays a preview', function () {
    [$assignment, $trainingLesson, $question] = trainableAssignment();
    $courseAttempt = CourseAttempt::factory()->create(['assignment_id' => $assignment->id, 'course_version_id' => $assignment->course_version_id]);
    $lessonAttempt = LessonAttempt::factory()->create(['course_attempt_id' => $courseAttempt->id, 'lesson_id' => $trainingLesson->id]);
    QuestionAttempt::factory()->create(['lesson_attempt_id' => $lessonAttempt->id, 'question_id' => $question->id, 'selected_option_ids' => [$question->options->first()->id]]);
    LessonProgress::factory()->create(['assignment_id' => $assignment->id, 'lesson_id' => $trainingLesson->id, 'watched_seconds' => 27, 'last_position_seconds' => 31]);
    app(ComplianceEventRecorder::class)->record(ComplianceEventType::AssignmentOpened, $assignment->user_id, ['assignment_id' => $assignment->id, 'course_version_id' => $assignment->course_version_id]);
    Certificate::factory()->create(['assignment_id' => $assignment->id, 'user_id' => $assignment->user_id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake('local');
    [$version, $lesson, $video, $link, $playback] = previewVideoGraph('local_fake');
    Storage::disk('local')->put('dev-videos/'.$video->provider_asset_id, 'preview-media-bytes');
    $tables = ['user_training_assignments', 'course_attempts', 'lesson_attempts', 'question_attempts', 'lesson_progress', 'compliance_events', 'certificates'];
    $snapshot = collect($tables)->mapWithKeys(function ($table) {
        $rows = DB::table($table)->orderBy('id')->get();
        expect($rows)->not->toBeEmpty();

        return [$table => $rows->toJson()];
    });
    $this->actingAs($assignment->user);
    $this->get($link['url'])->assertOk();
    $this->get(str_replace('/playback', '', $playback))->assertOk();
    $grant = $this->postJson($playback)->assertOk()->json();
    $this->get($grant['playback_url'])->assertOk();
    $this->get($grant['playback_url'], ['Range' => 'bytes=0-6'])->assertStatus(206);
    foreach ($snapshot as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});

it('preserves original fake-provider relative and absolute expiry and development byte serving', function () {
    $this->travelTo(now()->startOfSecond());
    $this->app['env'] = 'local';
    Route::get('/dev/videos/{asset}', [DevVideoController::class, 'show'])->middleware(['web', 'signed'])->name('dev.video.play');
    Route::getRoutes()->refreshNameLookups();
    $provider = app(FakeVideoProvider::class);
    Storage::fake('local');
    $video = Video::factory()->create(['provider' => 'local_fake']);
    $relative = $provider->createPlaybackAuthorization($video, 3);
    $absolute = $provider->createPlaybackAuthorization($video, 3, now()->addSeconds(19));
    expect($relative->expiresAt->timestamp)->toBe(now()->addMinutes(3)->timestamp);
    expect($absolute->expiresAt->timestamp)->toBe(now()->addSeconds(19)->timestamp);
    Storage::disk('local')->put($provider->path($video->provider_asset_id), 'abcdefghij');
    $response = $this->get($relative->playbackUrl)->assertOk();
    ob_start();
    $response->baseResponse->sendContent();
    $bytes = ob_get_clean();
    expect($bytes)->toBe('abcdefghij');
    $range = $this->get($absolute->playbackUrl, ['Range' => 'bytes=3-5'])->assertStatus(206);
    ob_start();
    $range->baseResponse->sendContent();
    $bytes = ob_get_clean();
    expect($bytes)->toBe('def');
    $this->get(preg_replace('/signature=[^&]+/', 'signature=invalid', $relative->playbackUrl))->assertForbidden();
    Storage::disk('local')->delete($provider->path($video->provider_asset_id));
    $this->get($relative->playbackUrl)->assertNotFound();
    $this->travel(4)->minutes();
    $this->get($relative->playbackUrl)->assertForbidden();
});

it('detects extensionless WebM bytes for full and Range preview and development responses', function () {
    Route::get('/dev/videos/{asset}', [DevVideoController::class, 'show'])->middleware(['web', 'signed'])->name('dev.video.play');
    Route::getRoutes()->refreshNameLookups();
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph('local_fake');
    // EBML header with the WebM document type; the UUID storage path has no extension.
    $bytes = hex2bin('1a45dfa39f4286810142f7810142f2810442f381084282847765626d428781024285810218538067ff');
    $provider = app(FakeVideoProvider::class);
    Storage::disk(FakeVideoProvider::DISK)->put($provider->path($video->provider_asset_id), $bytes);
    $preview = $this->postJson($url)->assertOk()->json('playback_url');
    $this->app['env'] = 'local';
    $development = $provider->createPlaybackAuthorization($video, 1)->playbackUrl;
    foreach ([$preview, $development] as $playbackUrl) {
        $full = $this->get($playbackUrl)->assertOk()->assertHeader('Content-Type', 'video/webm')->assertHeader('Accept-Ranges', 'bytes');
        ob_start();
        $full->baseResponse->sendContent();
        expect(ob_get_clean())->toBe($bytes);
        $range = $this->get($playbackUrl, ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Content-Type', 'video/webm')->assertHeader('Content-Range', 'bytes 0-3/'.strlen($bytes));
        ob_start();
        $range->baseResponse->sendContent();
        expect(ob_get_clean())->toBe(substr($bytes, 0, 4));
    }
});

it('removes authored media from projection grants and an existing local signature without deleting the asset', function (string $content) {
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph('local_fake');
    $lesson->update(['content_markdown' => $content]);
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, 'retained-private-video');
    $grant = $this->postJson($url)->assertOk()->json();
    $this->get($grant['playback_url'])->assertOk();
    $lesson->update(['content_markdown' => 'Saved text without a video marker']);
    $this->get(str_replace('/playback', '', $url))->assertOk()->assertDontSee('data-course-preview-player', false);
    $this->postJson($url)->assertStatus(409)->assertJsonPath('error', 'media_unavailable')->assertDontSee('playback_url');
    $this->get($grant['playback_url'])->assertNotFound()->assertDontSee('retained-private-video');
    expect($video->fresh())->not->toBeNull();
    Storage::disk(FakeVideoProvider::DISK)->assertExists('dev-videos/'.$video->provider_asset_id);
})->with(['markdown' => "Before\n\n:::video\n\nAfter", 'html' => '<p>Before</p><div data-oceanix-video></div><p>After</p>']);

it('does not contact a provider without an authored marker and rechecks removal during provider latency', function () {
    app()->bind(VideoProvider::class, CloudflareStreamProvider::class);
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $lesson->update(['content_markdown' => 'No authored media']);
    Http::fake();
    $this->postJson($url)->assertStatus(409);
    Http::assertNothingSent();
    $lesson->update(['content_markdown' => ':::video']);
    Http::fake(function () use ($lesson) {
        $lesson->update(['content_markdown' => 'Marker removed while requesting grant']);

        return Http::response(['success' => true, 'result' => ['token' => 'must-not-leak']]);
    });
    $this->postJson($url)->assertStatus(409)->assertDontSee('must-not-leak')->assertDontSee('playback_url');
    Http::assertSentCount(1);
});

it('places one player at the authored marker between sanitized sections', function (string $content) {
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $lesson->update(['content_markdown' => $content]);
    $response = $this->get(str_replace('/playback', '', $url))->assertOk();
    $response->assertSeeInOrder(['Before authored media', 'data-course-preview-player', 'After authored media'], false)
        ->assertDontSee('lesson-video-placeholder', false)->assertDontSee('javascript:', false)->assertDontSee('onerror=', false)->assertDontSee('<script>attack()', false);
    expect(substr_count($response->getContent(), 'data-course-preview-player'))->toBe(1);
})->with([
    'markdown' => "Before authored media\n\n:::video\n\nAfter authored media\n\n[Unsafe](javascript:alert(1))",
    'html' => '<p>Before authored media</p><div data-oceanix-video></div><p>After authored media</p><script>attack()</script><img src="javascript:bad" onerror="attack()">',
]);

it('normalizes repeated authored markers to one player while preserving sanitized content order', function (string $content) {
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $lesson->update(['content_markdown' => $content]);
    $response = $this->get(str_replace('/playback', '', $url))->assertOk();
    $response->assertSeeInOrder(['Before first marker', 'data-course-preview-player', 'Between authored markers', 'After last marker'], false)
        ->assertDontSee('lesson-video-placeholder', false)->assertDontSee('data-oceanix-video', false)
        ->assertDontSee(':::video', false)->assertDontSee('javascript:', false)->assertDontSee('onerror=', false)
        ->assertDontSee('<script>attack()', false);
    expect(substr_count($response->getContent(), 'data-course-preview-player'))->toBe(1);
})->with([
    'markdown' => "Before first marker\n\n:::video\n\nBetween authored markers\n\n:::video\n\nAfter last marker\n\n[Unsafe](javascript:alert(1))",
    'html' => '<p>Before first marker</p><div data-oceanix-video></div><p>Between authored markers</p><div data-oceanix-video></div><p>After last marker</p><script>attack()</script><img src="javascript:bad" onerror="attack()">',
]);

it('renders localized sender guidance initially hidden for terminal player transitions', function () {
    [$version, $lesson, $video, $link, $url] = previewVideoGraph();
    $itemUrl = str_replace('/playback', '', $url);
    $this->get($itemUrl)->assertOk()->assertSee('data-ended-guidance hidden', false)
        ->assertSee('Entre em contato com quem enviou este link para obter mais informações.');
    $this->withSession(['preview_locale' => 'en'])->get($itemUrl)->assertOk()->assertSee('data-ended-guidance hidden', false)
        ->assertSee('Please contact the person who shared this link for more information.');
});
