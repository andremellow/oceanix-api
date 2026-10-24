<?php

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Oceanix\Platform\Livewire\People;
use Oceanix\Platform\Livewire\Person;
use Oceanix\Platform\Livewire\Profile;
use Oceanix\Platform\Livewire\Profiles;

beforeEach(function () {
    seedAccessCatalog();
    config(['oceanix-platform.ui.slots' => []]);
});

it('renders the SDK directory without Compliance fields or sections', function () {
    $actor = adminUser();
    Livewire::actingAs($actor)->test(People::class)
        ->assertSee($actor->name)->assertSee('WorkOS invitations')
        ->assertDontSee('Open training')->assertDontSee('Overdue training');
    Livewire::test(Person::class, ['user' => $actor])
        ->assertSee('Access profiles')->assertDontSee('Training record')->assertDontSee('Management responsibilities');
});

it('creates profiles and expands permissions through the package screens', function () {
    Livewire::actingAs(adminUser())->test(Profiles::class)
        ->set('name', 'SDK screen operator')->set('description', 'Shared screen')
        ->call('save')->assertHasNoErrors();
    $role = Role::where('name', 'SDK screen operator')->sole();
    Livewire::test(Profile::class, ['role' => $role])
        ->set('selected', [Permission::PeopleInvite->value])->call('save')->assertHasNoErrors();
    expect($role->permissions()->pluck('key')->sort()->values()->all())->toBe(['people.invite', 'people.view']);
});

it('denies direct profile screens to people without administration', function () {
    Livewire::actingAs(employeeUser())->test(Profiles::class)->assertForbidden();
});

it('rechecks permissions on a later Livewire request', function () {
    $actor = userWithPermissions([Permission::PeopleView]);
    $screen = Livewire::actingAs($actor)->test(People::class);
    $actor->roles()->detach();
    $screen->call('clearFilters')->assertForbidden();
});

it('rejects a foreign person passed directly to the package screen', function () {
    $actor = adminUser();
    $other = Company::create(['name' => 'Other UI company', 'slug' => 'other-ui-company', 'status' => 'active']);
    $context = app(TenantContext::class);
    $current = $context->get();
    $context->set($other);
    $person = User::factory()->create();
    $context->set($current);
    expect(fn () => Livewire::actingAs($actor)->test(Person::class, ['user' => $person]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not substitute a session company for an unknown UI URL', function () {
    $this->actingAs(adminUser())->get('/c/missing-ui-company/people')->assertForbidden();
});

it('locks the profile identity against client-side replacement', function () {
    $role = Role::factory()->create();
    $screen = Livewire::actingAs(adminUser())->test(Profile::class, ['role' => $role]);
    expect(fn () => $screen->set('role', 999999))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('redirects an authenticated person to the configured destination from SDK login', function () {
    $this->actingAs(adminUser())->get(route('login'))->assertRedirect(route('dashboard'));
});
