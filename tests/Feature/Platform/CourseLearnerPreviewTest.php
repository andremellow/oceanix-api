<?php

use App\Contracts\VideoProvider;
use App\Data\Video\PlaybackAuthorization;
use App\Exceptions\VideoProviderException;
use App\Models\Account;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function learnerPreviewFixture(): array
{
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    Storage::fake(FakeVideoProvider::DISK);
    $actor = Account::factory()->platformAdmin()->create();
    $version = CourseVersion::factory()->shared()->published()->create(['title' => 'Published course']);
    $version->course->update(['current_published_version_id' => $version->id]);
    $lesson = Lesson::factory()->create(['company_id' => null, 'is_shared' => true, 'course_version_id' => null, 'title' => 'Exact published module', 'content_markdown' => "Before video\n\n:::video\n\nAfter video"]);
    $composition = CourseVersionModule::create(['course_version_id' => $version->id, 'lesson_id' => $lesson->id, 'position' => 1, 'is_required' => true]);
    $video = Video::factory()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider' => 'local_fake']);
    Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, 'synthetic-preview-bytes');
    $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => 'What would you do?']);
    QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'First choice', 'is_correct' => true]);
    $params = ['course' => $version->course_id, 'version' => $version->id, 'kind' => 'composition', 'item' => $composition->id];

    return [$actor, $version, $lesson, $params];
}

it('renders the exact selected version as a learner without training or authoring writes', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $draft = CourseVersion::factory()->create(['course_id' => $version->course_id, 'version_number' => 2, 'status' => 'draft', 'title' => 'Other draft']);
    $this->withSession(['platform_account_id' => $actor->id]);
    $tables = ['user_training_assignments', 'course_attempts', 'lesson_attempts', 'question_attempts', 'compliance_events', 'certificates', 'lesson_progress', 'course_versions', (new CourseVersionModule)->getTable(), 'lessons', 'course_preview_links'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();

    $this->get(route('platform.shared-courses.preview', $params))->assertOk()
        ->assertSee('Published course')->assertSee('Exact published module')->assertDontSee('Other draft')
        ->assertSeeInOrder(['Before video', 'data-course-preview-player', 'After video'], false)
        ->assertSee('What would you do?')->assertSee('First choice')
        ->assertDontSee('is_correct')->assertDontSee('wire:click="answer', false)
        ->assertDontSee('data-training', false)->assertDontSee('wire:snapshot', false);
    $grant = $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertOk()->json();
    $this->get($grant['playback_url'], ['Range' => 'bytes=0-8'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-8/23');
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all())->toBe($before);
    expect(now()->diffInSeconds($grant['expires_at'], false))->toBeLessThanOrEqual(60);
});

it('offers the preview on the selected published version without creating a draft', function () {
    [$actor, $version] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    Livewire::test('platform.shared-courses.show', ['course' => $version->course])
        ->assertSee(__('Preview as learner'))
        ->assertSee(route('platform.shared-courses.preview', [$version->course, $version]), false);
    expect($version->course->versions()->count())->toBe(1);
});

it('supports a selected draft and a friendly empty overview', function () {
    [$actor, $version] = learnerPreviewFixture();
    $draft = CourseVersion::factory()->create(['course_id' => $version->course_id, 'version_number' => 2, 'status' => 'draft', 'title' => 'Empty draft']);
    $this->withSession(['platform_account_id' => $actor->id]);
    $this->get(route('platform.shared-courses.preview', [$version->course, $draft]))->assertOk()->assertSee('Empty draft')->assertSee(__('ui.no_lessons'));
});

it('rejects foreign versions items and discarded versions', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $other = CourseVersion::factory()->shared()->create();
    $this->get(route('platform.shared-courses.preview', [...$params, 'version' => $other->id]))->assertNotFound();
    $this->get(route('platform.shared-courses.preview', [...$params, 'item' => 99999]))->assertNotFound();
    $this->postJson(route('platform.shared-courses.preview-playback', [...$params, 'item' => 99999]))->assertNotFound();
    DB::table('course_versions')->where('id', $version->id)->update(['status' => 'discarded']);
    $this->get(route('platform.shared-courses.preview', $params))->assertNotFound();
});

it('rejects unauthenticated access and immediately revokes page grant and signed media access', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->get(route('platform.shared-courses.preview', $params))->assertRedirect(route('platform.login'));
    $this->withSession(['platform_account_id' => $actor->id]);
    $grant = $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertOk()->json();
    $actor->update(['is_platform_admin' => false]);
    $this->get(route('platform.shared-courses.preview', $params))->assertRedirect(route('platform.login'));
    $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertRedirect(route('platform.login'));
    $this->get($grant['playback_url'])->assertRedirect(route('platform.login'));
});

it('rejects removed authored video and retains readable content when the asset is missing', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $grant = $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertOk()->json();
    Storage::disk(FakeVideoProvider::DISK)->delete('dev-videos/'.$lesson->video->provider_asset_id);
    $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertStatus(409);
    $this->get(route('platform.shared-courses.preview', $params))->assertOk()->assertSee('Before video')->assertSee('After video');
    $lesson->update(['content_markdown' => 'Saved without video']);
    $this->get(route('platform.shared-courses.preview', $params))->assertOk()->assertDontSee('data-course-preview-player', false);
    $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertStatus(409);
    $this->get($grant['playback_url'])->assertNotFound();
});

it('returns a friendly provider failure without exposing diagnostics', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('key')->andReturn('local_fake');
    $provider->shouldReceive('createPlaybackAuthorization')->once()->andThrow(new VideoProviderException('Secret vendor details'));
    app()->instance(VideoProvider::class, $provider);
    $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertStatus(503)->assertSee(__('Video unavailable. Please try again.'))->assertDontSee('Secret vendor details');
});

it('rechecks access and authored media after provider latency', function (string $change) {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $provider = Mockery::mock(VideoProvider::class);
    $provider->shouldReceive('key')->andReturn('local_fake');
    $provider->shouldReceive('createPlaybackAuthorization')->once()->andReturnUsing(function ($video, $minutes, $expiry) use ($actor, $lesson, $change) {
        if ($change === 'access') {
            $actor->update(['is_platform_admin' => false]);
        } else {
            $lesson->update(['content_markdown' => 'Removed while waiting']);
        }

        return new PlaybackAuthorization('private-token', 'https://media.test/private-grant', $expiry);
    });
    app()->instance(VideoProvider::class, $provider);
    $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertStatus($change === 'access' ? 403 : 409)->assertDontSee('private-grant');
})->with(['access', 'content']);

it('rejects company-owned courses and expired signed media', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $private = CourseVersion::factory()->create();
    $this->get(route('platform.shared-courses.preview', [$private->course, $private]))->assertNotFound();
    $grant = $this->postJson(route('platform.shared-courses.preview-playback', $params))->assertOk()->json();
    $this->travel(61)->seconds();
    $this->get($grant['playback_url'])->assertForbidden();
});

it('uses the learner overview structure with metrics and actionable lesson rows', function () {
    [$actor, $version, $lesson, $params] = learnerPreviewFixture();
    $this->withSession(['platform_account_id' => $actor->id]);
    $response = $this->get(route('platform.shared-courses.preview', [$version->course, $version]))->assertOk();
    $response->assertSee($version->course->code)
        ->assertSeeInOrder([__('Progress'), __('Due date'), __('Content version'), __('Assigned by'), __('Lessons')])
        ->assertSee(__('Not assigned'))->assertSee(__('Not applicable'))
        ->assertSee('Exact published module')->assertSee(__('Start'))
        ->assertSee(route('platform.shared-courses.preview', $params))
        ->assertDontSee('aria-label="'.__('Course contents').'"', false);
    expect(substr_count($response->getContent(), 'class="metric-card '))->toBe(4);
});
