<?php

use App\Enums\ModuleVersionStatus;
use App\Models\Module;
use App\Models\ModuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks only draft module versions as editable', function (): void {
    expect((new ModuleVersion(['status' => ModuleVersionStatus::Draft]))->isEditable())->toBeTrue()
        ->and((new ModuleVersion(['status' => ModuleVersionStatus::Published]))->isEditable())->toBeFalse()
        ->and((new ModuleVersion(['status' => ModuleVersionStatus::Retired]))->isEditable())->toBeFalse();
});

it('blocks content changes to a published module version but permits retirement', function (): void {
    $module = Module::create([
        'company_id' => null,
        'is_shared' => true,
        'code' => 'IMMUTABLE',
        'title' => 'Immutable module',
        'version_number' => 0,
        'status' => 'draft',
    ]);
    $version = ModuleVersion::create([
        'company_id' => null,
        'is_shared' => true,
        'code' => $module->code,
        'lineage_uuid' => $module->lineage_uuid,
        'source_lesson_id' => $module->id,
        'version_number' => 1,
        'status' => ModuleVersionStatus::Published,
        'title' => 'Published title',
    ]);

    $version->title = 'Changed title';
    expect($version->save())->toBeFalse()
        ->and($version->fresh()->title)->toBe('Published title');

    $version->refresh()->status = ModuleVersionStatus::Retired;
    expect($version->save())->toBeTrue()
        ->and($version->fresh()->status)->toBe(ModuleVersionStatus::Retired);
});
