<?php

use App\Models\Account;
use App\Models\Module;
use App\Services\SharedContent\SharedContentCatalog;
use Livewire\Livewire;

it('lists the published identity with an independent draft flag and ignores discarded versions', function () {
    $published = Module::factory()->shared()->create(['title' => 'Published identity', 'status' => 'published', 'version_number' => 2]);
    foreach ([[1, 'retired', 'Old identity'], [3, 'draft', 'Unpublished edits'], [4, 'discarded', 'Abandoned edits']] as [$number, $status, $title]) {
        Module::factory()->shared()->create(['lineage_uuid' => $published->lineage_uuid, 'code' => $published->code, 'version_number' => $number, 'status' => $status, 'title' => $title]);
    }
    $catalog = app(SharedContentCatalog::class);
    $selected = $catalog->platformModules()->sole();
    expect($selected->id)->toBe($published->id)
        ->and($selected->title)->toBe('Published identity')
        ->and((bool) $selected->has_active_draft)->toBeTrue()
        ->and($catalog->platformModules('Abandoned edits'))->toBeEmpty();

    Module::query()->where('lineage_uuid', $published->lineage_uuid)->where('status', 'draft')->update(['status' => 'discarded']);
    $selected = $catalog->platformModules()->sole();
    expect($selected->id)->toBe($published->id)->and((bool) $selected->has_active_draft)->toBeFalse();
});

it('shows unpublished active drafts but omits discarded-only retired-only archived and private modules', function () {
    $draft = Module::factory()->shared()->create(['title' => 'First draft', 'status' => 'draft']);
    Module::factory()->shared()->create(['lineage_uuid' => $draft->lineage_uuid, 'code' => $draft->code, 'version_number' => 2, 'status' => 'discarded']);
    Module::factory()->shared()->create(['status' => 'discarded']);
    Module::factory()->shared()->create(['status' => 'retired']);
    Module::factory()->shared()->create(['status' => 'published', 'lineage_archived_at' => now()]);
    Module::factory()->create(['status' => 'published']);

    expect(app(SharedContentCatalog::class)->platformModules()->pluck('id')->all())->toBe([$draft->id]);
});

it('renders published version numbers and draft availability without discarded status or total version counts', function () {
    $actor = Account::factory()->platformAdmin()->create();
    $published = Module::factory()->shared()->create(['title' => 'Visible publication', 'status' => 'published', 'version_number' => 2]);
    Module::factory()->shared()->create(['lineage_uuid' => $published->lineage_uuid, 'code' => $published->code, 'version_number' => 3, 'status' => 'draft', 'title' => 'Hidden draft title']);
    Module::factory()->shared()->create(['lineage_uuid' => $published->lineage_uuid, 'code' => $published->code, 'version_number' => 4, 'status' => 'discarded']);
    Module::factory()->shared()->create(['title' => 'Discarded-only module', 'status' => 'discarded']);
    Module::factory()->shared()->create(['title' => 'Unpublished module', 'status' => 'draft', 'version_number' => 1]);
    $this->withSession(['platform_account_id' => $actor->id]);

    Livewire::test('platform.shared-modules.index')
        ->assertSee('Visible publication')->assertSee(__('Published version :number', ['number' => 2]))
        ->assertSee(__('Active draft'))->assertSee(__('Not published yet'))
        ->assertSee(__('Draft version :number', ['number' => 1]))
        ->assertDontSee('Hidden draft title')->assertDontSee('Discarded-only module')
        ->assertDontSee(__('Discarded'))
        ->assertSee(route('platform.shared-modules.show', $published), false);
});
