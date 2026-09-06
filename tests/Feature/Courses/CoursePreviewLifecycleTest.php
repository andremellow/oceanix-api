<?php

use App\Actions\Courses\GenerateCoursePreviewLink;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;

it('expires exactly after 168 hours and appends fresh generations without sliding lifetime', function () {
    $this->travelTo(now()->startOfSecond());
    $version = CourseVersion::factory()->create();
    $actor = adminUser();
    $action = app(GenerateCoursePreviewLink::class);
    $first = $action->handle($version->course, $version, $actor);
    $expiry = now()->addHours(168);
    expect(CoursePreviewLink::first()->expires_at->equalTo($expiry))->toBeTrue();
    $this->travelTo($expiry->copy()->subSecond());
    $this->get($first['url'])->assertOk();
    expect($action->handle($version->course, $version, $actor))->toBe($first);
    $this->travelTo($expiry);
    $this->get($first['url'])->assertGone()->assertDontSee($version->title)->assertSee('Esta prévia foi encerrada.');
    $second = $action->handle($version->course, $version, $actor);
    expect($second['url'])->not->toBe($first['url']);
    expect(CoursePreviewLink::count())->toBe(2);
    $this->get($first['url'])->assertGone();
    $this->get($second['url'])->assertOk();
});
it('ends new reads when the exact version or owner ceases to be eligible', function (string $change) {
    $version = CourseVersion::factory()->create(['title' => 'Private draft title']);
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    match ($change) {
        'published', 'discarded' => $version->update(['status' => $change]),
        'archived', 'retired' => $version->course->update(['status' => $change]),
        'inactive' => $version->course->company->update(['status' => 'inactive']),
    };
    $this->get($data['url'])->assertGone()->assertDontSee('Private draft title')->assertSee('Entre em contato');
})->with(['published', 'discarded', 'archived', 'retired', 'inactive']);
it('reads latest saved content of its exact version without inheriting another draft', function () {
    $version = CourseVersion::factory()->create(['title' => 'Original']);
    $data = app(GenerateCoursePreviewLink::class)->handle($version->course, $version, adminUser());
    $version->update(['title' => 'Saved edit']);
    $other = CourseVersion::factory()->create(['course_id' => $version->course_id, 'version_number' => 2, 'status' => 'published', 'title' => 'Other version']);
    $this->get($data['url'])->assertOk()->assertSee('Saved edit')->assertDontSee('Other version');
});
