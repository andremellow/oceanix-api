<?php

use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Services\Organization\ManagedPeopleScope;

it('includes every company user including the current administrator', function (): void {
    $administrator = adminUser();
    $otherAdministrator = adminUser();
    $employee = User::factory()->create();

    expect(app(ManagedPeopleScope::class)->userIds($administrator))
        ->toContain($administrator->id, $otherAdministrator->id, $employee->id);
});

it('derives recursive reports from managed departments and job functions', function (): void {
    $manas = Department::factory()->create(['name' => 'Manas']);
    $elder = JobFunction::factory()->create(['name' => 'Elder']);
    $outside = Department::factory()->create(['name' => 'Outside']);

    $manager = User::factory()->create();
    $nestedManager = User::factory()->create();
    $nestedReport = User::factory()->create();
    $unrelated = User::factory()->create();

    $manager->managedDepartments()->attach($manas);
    $nestedManager->departments()->attach($manas);
    $nestedManager->managedJobFunctions()->attach($elder);
    $nestedReport->jobFunctions()->attach($elder);
    $unrelated->departments()->attach($outside);

    expect(app(ManagedPeopleScope::class)->userIds($manager))
        ->toContain($nestedManager->id, $nestedReport->id)
        ->not->toContain($manager->id, $unrelated->id);
});

it('handles circular management scopes without looping or widening visibility', function (): void {
    $firstDepartment = Department::factory()->create();
    $secondDepartment = Department::factory()->create();

    $first = User::factory()->create();
    $second = User::factory()->create();
    $outside = User::factory()->create();

    $first->managedDepartments()->attach($firstDepartment);
    $second->departments()->attach($firstDepartment);
    $second->managedDepartments()->attach($secondDepartment);
    $first->departments()->attach($secondDepartment);

    expect(app(ManagedPeopleScope::class)->userIds($first))
        ->toBe([$second->id])
        ->not->toContain($outside->id);
});

it('ignores memberships that are not currently active', function (): void {
    $department = Department::factory()->create();
    $manager = User::factory()->create();
    $future = User::factory()->create();
    $expired = User::factory()->create();

    $manager->managedDepartments()->attach($department);
    $future->departments()->attach($department, ['starts_at' => now()->addDay()->toDateString()]);
    $expired->departments()->attach($department, ['ends_at' => now()->subDay()->toDateString()]);

    expect(app(ManagedPeopleScope::class)->userIds($manager))->toBe([]);
});
