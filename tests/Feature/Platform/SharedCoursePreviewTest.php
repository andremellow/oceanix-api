<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Contracts\VideoProvider;
use App\Enums\PlatformPermission;
use App\Models\Account;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('allows active platform administrators to share only platform drafts and denies revoked accounts', function () {
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $version = CourseVersion::factory()->shared()->create(['title' => 'Platform saved draft']);
    $url = route('platform.shared-courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]);
    $data = $this->withSession(['platform_account_id' => $account->id])->postJson($url)->assertCreated()->json();
    $this->getJson($url)->assertOk()->assertJson($data);
    expect(PlatformPermission::SharedCoursesGeneratePreviewLink->prerequisites())->toBe([PlatformPermission::SharedCoursesView, PlatformPermission::SharedCoursesUpdate]);
    $this->get($data['url'])->assertOk()->assertSee('Platform saved draft');
    $company = CourseVersion::factory()->create();
    expect(fn () => app(GenerateCoursePreviewLink::class)->handle($company->course, $company, $account))->toThrow(HttpException::class);
    $account->update(['is_platform_admin' => false]);
    $this->postJson($url)->assertRedirect();
    expect(fn () => app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $account))->toThrow(HttpException::class);
});

it('renders the platform draft sharing panel in detail and editor contexts', function () {
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $version = CourseVersion::factory()->shared()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    Livewire\Livewire::test('platform.shared-courses.show', ['course' => $version->course])->assertSee('Public draft preview');
    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $version->course])->assertSee('Public draft preview');
});

it('projects the selected shared lineage version with its own questions and video rather than the newer version', function () {
    Storage::fake(FakeVideoProvider::DISK);
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $this->withSession(['platform_account_id' => $account->id]);
    $version = CourseVersion::factory()->shared()->create();
    $selected = Lesson::factory()->create(['company_id' => null, 'is_shared' => true, 'course_version_id' => null, 'status' => 'published', 'content_markdown' => "Selected exact body\n\n:::video"]);
    $newer = Lesson::factory()->create(['company_id' => null, 'is_shared' => true, 'course_version_id' => null, 'lineage_uuid' => $selected->lineage_uuid, 'version_number' => 2, 'status' => 'published', 'content_markdown' => "Newer excluded body\n\n:::video"]);
    $item = CourseVersionModule::create(['course_version_id' => $version->id, 'lesson_id' => $selected->id, 'position' => 1, 'is_required' => true]);
    foreach ([[$selected, 'Selected question', 'Selected option'], [$newer, 'Newer question', 'Newer option']] as [$lesson, $prompt, $choice]) {
        $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => $prompt]);
        QuestionOption::factory()->correct()->create(['company_id' => null, 'question_id' => $question->id, 'text' => $choice]);
        $video = Video::factory()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider' => 'local_fake']);
        Storage::disk(FakeVideoProvider::DISK)->put('dev-videos/'.$video->provider_asset_id, $prompt.' media bytes');
    }
    app()->bind(VideoProvider::class, FakeVideoProvider::class);
    $link = $this->postJson(route('platform.shared-courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]))->assertCreated()->json();
    $params = ['token' => basename($link['url']), 'kind' => 'composition', 'item' => $item->id];
    $this->get(route('course-preview.item', $params))->assertOk()->assertSee('Selected exact body')->assertSee('Selected question')->assertSee('Selected option')->assertDontSee('Newer excluded body')->assertDontSee('Newer question')->assertDontSee('Newer option');
    $grant = $this->postJson(route('course-preview.playback', $params))->assertOk()->json();
    expect($grant['playback_url'])->toContain('/media/'.$selected->video->id.'?')->not->toContain('/media/'.$newer->video->id.'?');
});

it('publishes only saved editor state in a newly generated platform preview', function () {
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $version = CourseVersion::factory()->shared()->create(['description' => 'Persisted description']);
    $this->withSession(['platform_account_id' => $account->id]);
    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $version->course])
        ->set('versionForm.description', 'UNSAVED editorial description')
        ->assertSet('versionForm.description', 'UNSAVED editorial description')->assertSee('Public draft preview');
    $link = $this->postJson(route('platform.shared-courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]))->assertCreated()->json();
    $this->get($link['url'])->assertOk()->assertSee('Persisted description')->assertDontSee('UNSAVED editorial description');
    expect($version->fresh()->description)->toBe('Persisted description');
});

it('redirects revoked platform sharing reads and writes to the real HTML login response without credentials', function () {
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
    $version = CourseVersion::factory()->shared()->create();
    $url = route('platform.shared-courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]);
    $link = $this->withSession(['platform_account_id' => $account->id])->postJson($url)->assertCreated()->json();
    $account->update(['is_platform_admin' => false]);
    $this->getJson($url)->assertRedirect(route('platform.login'))->assertDontSee(basename($link['url']));
    $this->postJson($url)->assertRedirect(route('platform.login'))->assertDontSee(basename($link['url']));
    $response = $this->followingRedirects()->getJson($url)->assertOk()->assertDontSee(basename($link['url']));
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});
