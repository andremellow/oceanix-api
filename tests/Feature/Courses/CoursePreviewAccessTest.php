<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Company;
use App\Models\Course;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('grants separate sharing permission with prerequisites and exposes an anonymous reusable link', function () {
    $user = userWithPermissions([Permission::CoursesGeneratePreviewLink]);
    $version = CourseVersion::factory()->create(['title' => 'Distinct draft']);
    expect(Permission::withPrerequisites([Permission::CoursesGeneratePreviewLink]))->toContain('courses.view', 'courses.update');
    $url = route('courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]);
    $data = $this->actingAs($user)->postJson($url)->assertCreated()->json();
    $this->getJson($url)->assertOk()->assertJson($data);
    auth()->forgetGuards();
    $this->get($data['url'], ['Accept-Language' => 'en'])->assertOk()->assertSee('Distinct draft')->assertSee('Prévia do rascunho')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect(CoursePreviewLink::count())->toBe(1);
});
it('denies direct action and route without sharing and after profile revocation', function () {
    $user = userWithPermissions([Permission::CoursesUpdate]);
    $version = CourseVersion::factory()->create();
    $this->actingAs($user)->postJson(route('courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]))->assertForbidden();
    expect(fn () => app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $user))->toThrow(AuthorizationException::class);
    $profile = grantPermissions($user, [Permission::CoursesGeneratePreviewLink]);
    app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $user);
    $profile->update(['archived_at' => now()]);
    expect(fn () => app(GenerateCoursePreviewLink::class)->retrieve($version->course, $version, $user))->toThrow(AuthorizationException::class);
});
it('applies ownership guards even to administrator bypass and mismatched versions', function () {
    $admin = adminUser();
    Gate::before(fn () => true);
    $own = CourseVersion::factory()->create();
    expect(app(GenerateCoursePreviewLink::class)->handle($own->course, $own, $admin)['state'])->toBe('active');
    $foreign = CourseVersion::factory()->for(Course::factory()->create(['company_id' => Company::factory()->create()->id]))->create();
    $shared = CourseVersion::factory()->shared()->create();
    foreach ([$foreign, $shared] as $version) {
        expect(fn () => app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $admin))->toThrow(HttpException::class);
    }
    expect(fn () => app(GenerateCoursePreviewLink::class)->handle($own->course, $foreign, $admin))->toThrow(HttpException::class);
});
it('keeps public access independent of inactive company and locale sessions', function () {
    $version = CourseVersion::factory()->create();
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $inactive = Company::factory()->create(['status' => 'inactive']);
    $this->withSession(['company_id' => $inactive->id, 'locale' => 'en', 'preview_locale' => 'invalid'])->get($data['url'])->assertOk()->assertSee('Prévia do rascunho')->assertSessionHas('company_id', $inactive->id);
    $token = basename($data['url']);
    $this->post(route('course-preview.locale', ['token' => $token, 'locale' => 'en']))->assertRedirect($data['url']);
    $this->get($data['url'])->assertOk()->assertSee('Draft preview');
    $this->post(route('course-preview.locale', ['token' => $token, 'locale' => 'fr']))->assertNotFound();
});
it('hides token storage and protects immutable generation history', function () {
    $link = CoursePreviewLink::factory()->create();
    expect($link->toArray())->not->toHaveKeys(['token_hash', 'token_encrypted']);
    expect($link->getRawOriginal('token_encrypted'))->not->toBe($link->token_encrypted);
    expect(fn () => $link->update(['expires_at' => now()]))->toThrow(LogicException::class);
    expect(fn () => $link->fresh()->delete())->toThrow(LogicException::class);
});
it('renders sharing in course show and editor and removes it on rehydration after revocation', function () {
    $user = userWithPermissions([Permission::CoursesGeneratePreviewLink]);
    $version = CourseVersion::factory()->create();
    $this->actingAs($user);
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, $user);
    $component = Livewire::test('courses.show', ['company' => currentCompany(), 'course' => $version->course])->assertSee('Public draft preview')->assertSee(basename($data['url']), false);
    $user->roles()->update(['archived_at' => now()]);
    $component->call('$refresh')->assertDontSee(basename($data['url']), false);
});

it('localizes and protects throttled and CSRF error responses', function () {
    $version = CourseVersion::factory()->create();
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $key = 'course-preview:read:'.hash('sha256', '127.0.0.1');
    for ($i = 0; $i < 60; $i++) {
        RateLimiter::hit($key, 60);
    }
    $this->getJson($data['url'])->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Referrer-Policy', 'no-referrer');
    RateLimiter::clear($key);
    $this->app['env'] = 'production';
    $this->postJson(route('course-preview.locale', ['token' => basename($data['url']), 'locale' => 'en']))->assertStatus(419)->assertJsonPath('error', 'session_expired')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

it('shows the sharing panel in the company draft editor without saving unsaved fields', function () {
    $version = CourseVersion::factory()->create(['title' => 'Saved title']);
    Livewire::actingAs(userWithPermissions([Permission::CoursesGeneratePreviewLink]))
        ->test('courses.editor', ['course' => $version->course])->assertSee('Public draft preview')->assertSee('Unsaved changes are not included.');
    expect($version->fresh()->title)->toBe('Saved title');
});

it('returns 201 for new or renewed generations and 200 for reuse in both authoring contexts', function (bool $platform) {
    $version = $platform ? CourseVersion::factory()->shared()->create() : CourseVersion::factory()->create();
    if ($platform) {
        $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active']);
        $this->withSession(['platform_account_id' => $account->id]);
    } else {
        $this->actingAs(adminUser());
    }
    $url = route($platform ? 'platform.shared-courses.preview-link' : 'courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]);
    $first = $this->postJson($url)->assertCreated()->json();
    $this->postJson($url)->assertOk()->assertExactJson($first);
    $this->travelTo(CoursePreviewLink::first()->expires_at);
    $second = $this->postJson($url)->assertCreated()->json();
    expect($second['url'])->not->toBe($first['url']);
    $this->postJson($url)->assertOk()->assertExactJson($second);
    $this->get($first['url'])->assertGone();
})->with([false, true]);
