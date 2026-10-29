<?php

use App\Models\AuditLog;
use App\Models\Course;
use Database\Factories\AccountFactory;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Livewire;
use Oceanix\Platform\Livewire\PlatformCompany;
use Oceanix\Platform\Models\Account;
use Oceanix\Platform\Models\Company;
use Oceanix\Platform\Services\Platform\PlatformAccess;
use Oceanix\Platform\Support\Platform;

it('resolves historical and current audit subjects without deleted models or rewriting evidence', function (string $model, string $identity) {
    $subject = $model === Account::class ? AccountFactory::new()->create() : CompanyFactory::new()->create();
    $log = AuditLog::query()->create(['company_id' => currentCompany()->id, 'action' => 'historical.subject', 'auditable_type' => $identity, 'auditable_id' => $subject->id]);
    expect($log->fresh()->auditable)->toBeInstanceOf($model)
        ->and($log->fresh()->auditable->id)->toBe($subject->id)
        ->and($log->fresh()->auditable_type)->toBe($identity)
        ->and(Relation::requiresMorphMap())->toBeFalse();
})->with([
    [Account::class, 'App\\Models\\Account'], [Company::class, 'App\\Models\\Company'],
    [Account::class, Account::class], [Company::class, Company::class],
]);

it('keeps explicit revoked platform sessions authoritative over a different active tenant account', function () {
    $person = adminUser();
    $active = AccountFactory::new()->platformAdmin()->create();
    $revoked = AccountFactory::new()->platformAdmin()->create(['status' => 'inactive']);
    $person->update(['account_id' => $active->id]);
    $person->load('account');
    $this->actingAs($person)->withSession(['platform_account_id' => $revoked->id]);
    expect(app(PlatformAccess::class)->account())->toBeNull();
    $this->get(route('platform.users'))->assertForbidden();
});

it('uses package routes and common views with real installed version and a course-only extension', function () {
    $company = currentCompany();
    $course = Course::factory()->create(['title' => 'Course inside package company detail']);
    $account = AccountFactory::new()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    $this->get(route('platform.companies.show', $company))->assertOk()
        ->assertSee('Oceanix SDK '.Platform::version())->assertSee($course->title)
        ->assertSeeLivewire(PlatformCompany::class)->assertSeeLivewire('platform.company-courses');
    expect(app(PlatformAccess::class)::class)->toBe(PlatformAccess::class);
    foreach (['platform.dashboard', 'platform.companies', 'platform.companies.show', 'platform.users', 'platform.login'] as $name) {
        expect(collect(app('router')->getRoutes())->filter(fn ($route) => $route->getName() === $name))->toHaveCount(1);
    }
});

it('protects the selected company and promotion boundary in the extracted child', function () {
    $company = currentCompany();
    $other = CompanyFactory::new()->create();
    $foreign = Course::factory()->create(['company_id' => $other->id]);
    $account = AccountFactory::new()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    $child = Livewire::test('platform.company-courses', ['companyId' => $company->id]);
    $child->call('previewPromotion', $foreign->id)->assertHasErrors('promotion');
    expect($foreign->fresh()->is_shared)->toBeFalse();
    $account->update(['is_platform_admin' => false]);
    $child->call('previewPromotion', $foreign->id)->assertForbidden();
    expect($foreign->fresh()->is_shared)->toBeFalse();
});
