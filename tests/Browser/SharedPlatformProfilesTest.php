<?php

use App\Models\Role;
use Illuminate\Support\Facades\Http;

it('creates and saves a profile through the shared SDK without changing the existing screens', function () {
    seedAccessCatalog();
    config(['app.locale' => 'en']);
    $this->withSession(['locale' => 'en'])->actingAs(adminUser());
    Http::preventStrayRequests();

    $page = visit(route('admin.access-profiles', absolute: false));
    $page->fill('input[wire\\:model="name"]', 'Shared SDK operator')
        ->fill('input[wire\\:model="description"]', 'Shared platform browser verification')
        ->press('Create profile')
        ->assertSee('Shared SDK operator')
        ->check('input[value="people.invite"]')
        ->press('Save profile')
        ->assertSee('Access profile saved. Changes apply on the next request.')
        ->assertNoJavaScriptErrors();

    $profile = Role::where('name', 'Shared SDK operator')->sole();
    expect($profile->permissions()->pluck('key')->sort()->values()->all())
        ->toBe(['people.invite', 'people.view']);
});
