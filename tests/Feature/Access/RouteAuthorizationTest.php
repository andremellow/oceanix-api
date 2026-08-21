<?php

use App\Enums\Permission;

it('sends guests to sign-in', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'my-training', 'courses.index', 'assignments.index', 'people.index', 'people.import', 'admin.users']);

it('denies operational screens to an employee without permissions', function (string $route): void {
    $this->actingAs(employeeUser())->get(route($route))->assertForbidden();
})->with([
    'courses.index',
    'requirements.index',
    'assignments.index',
    'certificates.index',
    'people.index',
    'people.import',
    'departments.index',
    'job-functions.index',
    'audit-log',
    'admin.users',
    'admin.access-profiles',
]);

it('lets an employee reach their own training', function (): void {
    $user = employeeUser();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('my-training'))->assertOk();
});

it('grants a screen exactly to the permission that protects it', function (): void {
    $user = userWithPermissions([Permission::CoursesView]);

    $this->actingAs($user)->get(route('courses.index'))->assertOk();
    $this->actingAs($user)->get(route('assignments.index'))->assertForbidden();
});

it('lets an administrator through every screen', function (string $route): void {
    $this->actingAs(adminUser())->get(route($route))->assertOk();
})->with([
    'dashboard',
    'courses.index',
    'requirements.index',
    'assignments.index',
    'certificates.index',
    'people.index',
    'people.import',
    'departments.index',
    'job-functions.index',
    'audit-log',
    'admin.users',
    'admin.access-profiles',
]);

it('revokes access as soon as the profile loses the permission', function (): void {
    $user = userWithPermissions([Permission::CoursesView]);

    $this->actingAs($user)->get(route('courses.index'))->assertOk();

    $user->roles()->first()->permissions()->detach();

    $this->actingAs($user->fresh())->get(route('courses.index'))->assertForbidden();
});

it('ignores permissions granted through an archived profile', function (): void {
    $user = userWithPermissions([Permission::CoursesView]);
    $user->roles()->first()->update(['archived_at' => now()]);

    $this->actingAs($user->fresh())->get(route('courses.index'))->assertForbidden();
});
