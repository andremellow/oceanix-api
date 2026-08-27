<?php

use App\Models\Account;
use App\Models\Course;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('does not let the tenant administrator bypass shared course write ownership', function (): void {
    $administrator = adminUser();
    $sharedCourse = (new Course)->forceFill(['company_id' => null, 'is_shared' => true]);

    expect(Gate::forUser($administrator)->allows('view', $sharedCourse))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('update', $sharedCourse))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('publish', $sharedCourse))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('retire', $sharedCourse))->toBeFalse();
});

it('does not let the tenant administrator bypass shared module write ownership', function (): void {
    $administrator = adminUser();
    $sharedModule = (new Module)->forceFill(['company_id' => null, 'is_shared' => true]);

    expect(Gate::forUser($administrator)->allows('view', $sharedModule))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('update', $sharedModule))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('publish', $sharedModule))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('retire', $sharedModule))->toBeFalse();
});

it('lets a platform administrator write shared course and module records', function (): void {
    $platformAdministrator = User::factory()->create([
        'account_id' => Account::factory()->platformAdmin(),
    ]);
    $sharedCourse = (new Course)->forceFill(['company_id' => null, 'is_shared' => true]);
    $sharedModule = (new Module)->forceFill(['company_id' => null, 'is_shared' => true]);

    expect(Gate::forUser($platformAdministrator)->allows('update', $sharedCourse))->toBeTrue()
        ->and(Gate::forUser($platformAdministrator)->allows('publish', $sharedCourse))->toBeTrue()
        ->and(Gate::forUser($platformAdministrator)->allows('update', $sharedModule))->toBeTrue()
        ->and(Gate::forUser($platformAdministrator)->allows('publish', $sharedModule))->toBeTrue();
});

it('keeps the administrator bypass for records owned by the selected company', function (): void {
    $administrator = adminUser();
    $companyCourse = (new Course)->forceFill([
        'company_id' => $administrator->company_id,
        'is_shared' => false,
    ]);
    $companyModule = (new Module)->forceFill([
        'company_id' => $administrator->company_id,
        'is_shared' => false,
    ]);

    expect(Gate::forUser($administrator)->allows('update', $companyCourse))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('update', $companyModule))->toBeTrue();
});
