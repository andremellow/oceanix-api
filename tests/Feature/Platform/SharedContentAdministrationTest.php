<?php

use App\Models\Account;
use App\Models\Course;

it('protects every shared course administration route with platform access', function (): void {
    $course = Course::factory()->shared()->create();

    $routes = [
        route('platform.shared-courses.index'),
        route('platform.shared-courses.show', ['course' => $course]),
        route('platform.shared-courses.editor', ['course' => $course]),
    ];

    foreach ($routes as $route) {
        $this->actingAs(adminUser())->get($route)->assertForbidden();
    }
});

it('lets a platform administrator open the shared course directory and navigation', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    Course::factory()->shared()->create(['title' => 'Global Offshore Safety']);

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.index'))
        ->assertOk()
        ->assertSee('Global Offshore Safety')
        ->assertSee(__('Shared courses'))
        ->assertSee(route('platform.shared-courses.index'), escape: false);
});

it('rejects company-owned courses from platform shared detail and editor routes', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->create();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.show', ['course' => $course]))
        ->assertNotFound();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.editor', ['course' => $course]))
        ->assertNotFound();
});

it('creates shared courses with platform ownership from the directory', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.index')
        ->set('code', 'GLOBAL-101')
        ->set('title', 'Global Safety')
        ->set('description', 'Reusable offshore safety training.')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $course = Course::query()->where('code', 'GLOBAL-101')->firstOrFail();
    expect($course->is_shared)->toBeTrue()
        ->and($course->company_id)->toBeNull()
        ->and($course->draftVersion())->not->toBeNull();
});
