<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;

it('creates fifty predictably organized demo users and manager scopes', function (): void {
    $this->seed(DemoDataSeeder::class);

    expect(User::query()->count())->toBe(50)
        ->and(Department::query()->where('code', 'OPS')->firstOrFail()->users()->count())->toBeGreaterThanOrEqual(12)
        ->and(Department::query()->where('code', 'MNT')->firstOrFail()->users()->count())->toBeGreaterThanOrEqual(12)
        ->and(Department::query()->where('code', 'HSE')->firstOrFail()->users()->count())->toBeGreaterThanOrEqual(12)
        ->and(Department::query()->where('code', 'MAR')->firstOrFail()->users()->count())->toBeGreaterThanOrEqual(12)
        ->and(User::query()->where('email', 'marina.costa@example.com')->firstOrFail()->managedDepartments()->where('code', 'OPS')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'demo.ops.09@example.com')->firstOrFail()->managedDepartments()->where('code', 'MNT')->exists())->toBeTrue();
});
