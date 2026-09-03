<?php

use App\Enums\CourseStatus;
use App\Enums\ModuleStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Module;
use App\Models\ModuleVersion;
use Livewire\Livewire;

it('confirms and archives a shared course while explaining preserved evidence', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = archivableSharedCourse();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-courses.show', ['course' => $course])
        ->assertSee(__('Archive shared course'))
        ->set('confirmingArchive', true)
        ->assertSee(__('Existing assignments and evidence remain available.'))
        ->set('archiveReason', 'Replaced by updated standard')
        ->call('archive')
        ->assertHasNoErrors()
        ->assertSee(__('Shared course archived.'))
        ->assertSee(__('New associations and assignments are blocked.'));

    expect($course->fresh()->status)->toBe(CourseStatus::Archived);
});

it('archives a shared module lineage without rewriting historical version statuses', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $module = Module::factory()->shared()->create(['status' => ModuleStatus::Active]);
    $version = ModuleVersion::factory()->published()->create(['module_id' => $module->id]);
    $module->update(['current_published_version_id' => $version->id]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.show', ['module' => $module])
        ->assertSee(__('Archive shared module'))
        ->set('confirmingArchive', true)
        ->assertSee(__('Published courses and existing evidence remain unchanged.'))
        ->set('archiveReason', 'Module replaced')
        ->call('archive')
        ->assertHasNoErrors()
        ->assertSee(__('Shared module archived.'))
        ->assertSee(__('Archived'));

    expect($module->fresh())
        ->status->toBe(ModuleStatus::Active->value)
        ->lineage_archived_at->not->toBeNull()
        ->and($version->fresh()->status)->toBe(ModuleVersionStatus::Published)
        ->and($version->fresh()->lineage_archived_at)->not->toBeNull();
});
