<?php

use App\Models\User;
use Composer\InstalledVersions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Oceanix\Platform\Http\Controllers\LocaleController;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.locale' => 'en']);
});

it('uses SDK navigation and brand slots across platform screens', function (string $route, string $label) {
    $account = AccountFactory::new()->platformAdmin()->create();
    if ($route === 'platform.tasks.index') {
        User::factory()->create(['account_id' => $account->id, 'email' => $account->email]);
    }
    $this->withSession(['platform_account_id' => $account->id, 'locale' => 'en']);
    $rendered = [];
    View::composer('*', function ($view) use (&$rendered): void {
        if (realpath($view->getPath()) === realpath(base_path('vendor/oceanix/platform/resources/views/layouts/platform.blade.php'))) {
            $rendered[] = 'sdk-platform-shell';
        }
        if (in_array($view->name(), ['platform-extensions.platform_name', 'platform-extensions.platform_logo'], true)) {
            $rendered[] = $view->name();
        }
    });

    $response = $this->get(route($route))->assertOk()->assertSee('Oceanix SDK '.InstalledVersions::getPrettyVersion('oceanix/platform'));
    expect($rendered)->toContain('sdk-platform-shell', 'platform-extensions.platform_name', 'platform-extensions.platform_logo');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $active = $xpath->query('//*[@id="platform-sidebar"]//nav//a[@aria-current="page"]');
    expect($active)->toHaveCount(1)
        ->and(trim($active->item(0)->textContent))->toBe($label)
        ->and($xpath->query('//*[@id="platform-sidebar"]//nav//a'))->toHaveCount(6)
        ->and($xpath->query('//*[@id="platform-sidebar"]//footer//form[input[@name="return_to"]]'))->toHaveCount(1);
})->with([
    ['platform.dashboard', 'Home'],
    ['platform.companies', 'Companies'],
    ['platform.users', 'Platform administrators'],
    ['platform.shared-courses.index', 'Shared courses'],
    ['platform.shared-modules.index', 'Shared modules'],
    ['platform.tasks.index', 'Tasks'],
]);

it('uses one SDK locale route and preserves platform queries across full page reloads', function () {
    $account = AccountFactory::new()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id, 'locale' => 'en']);
    $routes = collect(app('router')->getRoutes())->filter(fn ($route) => $route->getName() === 'locale.update');
    expect($routes)->toHaveCount(1)
        ->and($routes->first()->getActionName())->toBe(LocaleController::class);
    $path = route('platform.shared-courses.index', absolute: false).'?search=Safety';

    $this->post(route('locale.update'), ['locale' => 'pt_BR', 'return_to' => $path])
        ->assertStatus(303)->assertRedirect(url($path))->assertSessionHas('locale', 'pt_BR');
    $this->get($path)->assertOk()->assertSeeHtml('lang="pt-BR"')->assertSee('Início')->assertSee('Administradores da plataforma')->assertSee('Tarefas');

    $this->post(route('locale.update'), ['locale' => 'en', 'return_to' => $path])->assertStatus(303);
    $this->get($path)->assertOk()->assertSeeHtml('lang="en"')->assertSee('Home')->assertSee('Shared courses');
    Http::assertNothingSent();
});

it('places the shared locale control beside company sign out and preserves the tenant context', function () {
    seedAccessCatalog();
    $person = adminUser();
    $company = currentCompany();
    $this->actingAs($person)->withSession(['company_id' => $company->id, 'locale' => 'en']);
    $path = route('dashboard', absolute: false).'?filter=all';

    $response = $this->get($path)->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $selector = $xpath->query('//aside//form[input[@name="return_to"]]');
    expect($selector)->toHaveCount(1)
        ->and($xpath->query('//header//form[input[@name="return_to"]]'))->toHaveCount(0)
        ->and($xpath->query('//aside//form[@action="'.route('logout').'"]'))->toHaveCount(1)
        ->and($xpath->query('.//input[@name="return_to"]', $selector->item(0))->item(0)->getAttribute('value'))->toBe($path);

    $this->post(route('locale.update'), ['locale' => 'pt_BR', 'return_to' => $path])
        ->assertStatus(303)->assertRedirect(url($path))->assertSessionHas('company_id', $company->id);
    $this->get($path)->assertOk()->assertSeeHtml('lang="pt-BR"');
    $this->assertAuthenticatedAs($person);
    Http::assertNothingSent();
});

it('rejects invalid global locale changes without modifying the session', function (string $locale, string $returnTo, string $field) {
    $this->withSession(['locale' => 'pt_BR'])
        ->post(route('locale.update'), ['locale' => $locale, 'return_to' => $returnTo])
        ->assertSessionHasErrors($field)->assertSessionHas('locale', 'pt_BR');
})->with([
    ['es', '/platform', 'locale'],
    ['en', '//example.org', 'return_to'],
    ['en', 'https://example.org', 'return_to'],
]);
