<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Contracts\VideoProvider;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Courses\PublicPreviewResolver;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Nightwatch\Facades\Nightwatch;

it('excludes token-bearing failures from sampling and exception logs while retaining unrelated reporting', function () {
    $token = str_repeat('b', 64);
    $resolver = Mockery::mock(PublicPreviewResolver::class);
    $resolver->shouldReceive('resolve')->with($token)->andThrow(new RuntimeException('secret '.$token));
    app()->instance(PublicPreviewResolver::class, $resolver);
    Nightwatch::sample(1);
    expect(Nightwatch::sampling())->toBeTrue();
    Log::spy();
    $failure = $this->getJson('/preview/courses/'.$token)->assertStatus(503)->assertDontSee($token)->assertJsonPath('error', 'temporarily_unavailable')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect($failure->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(Nightwatch::sampling())->toBeFalse();
    $handler = app(ExceptionHandler::class);
    app()->instance('request', Request::create('/preview/courses/'.$token));
    $handler->report(new RuntimeException('secret '.$token));
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('critical');
    app()->instance('request', Request::create('/ordinary-route'));
    $handler->report(new RuntimeException('ordinary diagnostic'));
    Log::shouldHaveReceived('error')->once();
});

it('applies all privacy headers to HTML JSON media and every public error boundary', function () {
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake('local');
    $version = CourseVersion::factory()->create();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);
    $video = Video::factory()->create(['lesson_id' => $lesson->id, 'provider' => 'local_fake']);
    Storage::disk('local')->put('dev-videos/'.$video->provider_asset_id, 'abcdef');
    $link = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $params = ['token' => basename($link['url']), 'kind' => 'composition', 'item' => CourseVersionModule::where('lesson_id', $lesson->id)->first()->id];
    $playback = route('course-preview.playback', $params);
    $assert = function ($response, int $status) {
        $response->assertStatus($status)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
        expect($response->headers->get('Cache-Control'))->not->toContain('public', 'max-age');

        return $response;
    };
    $assert($this->get($link['url']), 200);
    $assert($this->get(route('course-preview.item', $params)), 200);
    $grant = $assert($this->postJson($playback), 200)->json();
    $assert($this->get($grant['playback_url']), 200);
    $assert($this->get($grant['playback_url'], ['Range' => 'bytes=0-2']), 206);
    $assert($this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $grant['playback_url'])), 403);
    $assert($this->get('/preview/courses/invalid'), 404);
    $assert($this->getJson('/preview/courses/invalid'), 404);
    $assert($this->postJson($link['url']), 405);
    $video->update(['status' => 'processing']);
    $assert($this->postJson($playback), 409);
    $key = 'course-preview:read:'.hash('sha256', '127.0.0.1');
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit($key, 60);
    }
    $assert($this->getJson($link['url']), 429);
    RateLimiter::clear($key);
    $this->travelTo(CoursePreviewLink::first()->expires_at);
    $assert($this->get($link['url']), 410);
    $assert($this->postJson($playback), 410);
    $this->app['env'] = 'production';
    $assert($this->postJson(route('course-preview.locale', ['token' => $params['token'], 'locale' => 'en'])), 419);
});
