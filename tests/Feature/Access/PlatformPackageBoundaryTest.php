<?php

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Oceanix\Platform\Access\ProfileManager;
use Oceanix\Platform\Services\People\PersonProductAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => seedAccessCatalog());

it('rejects unknown profile permissions without replacing the existing grant set', function () {
    $actor = adminUser();
    $this->actingAs($actor);
    $manager = app(ProfileManager::class);
    $role = $manager->create($actor, 'Package boundary profile');
    $manager->savePermissions($actor, $role, ['people.view']);

    expect(fn () => $manager->savePermissions($actor, $role, ['people.invite', 'unknown.permission']))
        ->toThrow(ValidationException::class);
    expect($role->permissions()->pluck('key')->all())->toBe(['people.view']);
});

it('does not permit a delegated profile assignment to become protected administrator access', function () {
    $actor = userWithPermissions(['people.access-profiles.assign']);
    $person = employeeUser();
    $this->actingAs($actor);
    $admin = Role::where('key', 'admin')->firstOrFail();

    expect(fn () => app(ProfileManager::class)->toggle($actor, $person, $admin))->toThrow(HttpException::class);
    expect($person->fresh()->isAdmin())->toBeFalse();
});

it('denies another company profile without changing either company membership', function () {
    $actor = adminUser();
    $person = employeeUser();
    $this->actingAs($actor);
    $role = new Role;
    $role->forceFill(['id' => 999999, 'company_id' => $actor->company_id + 1, 'key' => 'foreign']);
    $before = $person->roles()->pluck('roles.id')->all();

    expect(fn () => app(ProfileManager::class)->toggle($actor, $person, $role))->toThrow(HttpException::class);
    expect($person->roles()->pluck('roles.id')->all())->toBe($before);
});

it('blocks an existing session after local person status is suspended', function () {
    $person = employeeUser();
    $this->actingAs($person);
    $this->get(route('dashboard'))->assertOk();
    expect(app(PersonProductAccess::class)->allows($person))->toBeTrue();

    User::whereKey($person->id)->update(['status' => UserStatus::Suspended]);
    expect(app(PersonProductAccess::class)->allows($person))->toBeFalse();
    $this->get(route('dashboard'))->assertForbidden();
    expect(User::findOrFail($person->id)->status)->toBe(UserStatus::Suspended);
});
