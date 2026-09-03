<?php

use App\Enums\ModuleVersionStatus;
use App\Enums\PlatformPermission as Permission;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Module $module;
    public ModuleVersion $version;

    public function mount(Module $module, PlatformAccess $access): void
    {
        $access->authorizePermission(Permission::SharedModulesView);
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
        $this->version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->with('video')->firstOrFail();
    }
};
?>

<div class="admin-page">
    <x-page-hero :kicker="__('Content preview')" :title="$version->title" :description="$version->description">
        <flux:button href="#" onclick="window.close(); return false;" variant="ghost">{{ __('Close preview') }}</flux:button>
    </x-page-hero>
    <article class="mx-auto mt-7 max-w-5xl rounded-[24px] border border-[#dce3e7] bg-white px-6 py-8 shadow-sm sm:px-10 sm:py-12"><x-lesson-content :lesson="$version" /></article>
</div>
