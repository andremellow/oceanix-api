<?php

use App\Enums\Permission;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\ApplicationSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Operational settings only. Credentials and infrastructure stay in the environment, where
 * they cannot be changed through a web form.
 */
new class extends Component
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(ApplicationSettings $settings): void
    {
        Gate::authorize(Permission::AppSettingsView->value);

        foreach (array_keys(ApplicationSettings::EDITABLE) as $key) {
            $this->values[$key] = $settings->get($key);
        }
    }

    public function save(ApplicationSettings $settings, AuditLogger $audit): void
    {
        Gate::authorize(Permission::AppSettingsUpdate->value);

        $rules = [];

        foreach (ApplicationSettings::EDITABLE as $key => $constraints) {
            $rules["values.{$key}"] = ['required', ...$constraints];
        }

        $before = $settings->all();
        $validated = $this->validate($rules)['values'];

        foreach ($validated as $key => $value) {
            $settings->set($key, is_numeric($value) ? (int) $value : (bool) $value);
        }

        $audit->log('app_settings.updated', before: $before, after: $settings->all());

        session()->flash('status', __('ui.settings_saved'));
    }

    public function with(): array
    {
        return [
            'labels' => [
                'oceanix.due_soon_days' => [__('ui.setting_due_soon'), __('ui.setting_due_soon_help')],
                'oceanix.critical_overdue_days' => [__('ui.setting_critical'), __('ui.setting_critical_help')],
                'oceanix.overdue_reminder_days' => [__('ui.setting_reminder'), __('ui.setting_reminder_help')],
                'oceanix.playback_token_minutes' => [__('ui.setting_token'), __('ui.setting_token_help')],
            ],
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.administration')"
        :title="__('Settings')"
        :description="__('ui.settings_page_description')">
        @can(App\Enums\Permission::AppSettingsUpdate->value)
            <flux:button wire:click="save" variant="primary" class="admin-primary-action">{{ __('Save settings') }}</flux:button>
        @endcan
    </x-page-hero>

    @if (session('status'))
        <flux:callout variant="success" :heading="session('status')" />
    @endif

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <div class="grid gap-5 lg:grid-cols-2">
            @foreach ($labels as $key => [$label, $help])
                <div wire:key="setting-{{ $key }}">
                    <flux:field>
                        <x-field-label :hint="$help">{{ $label }}</x-field-label>
                        <flux:input type="number" wire:model="values.{{ $key }}" class="admin-control" :disabled="! auth()->user()->can(App\Enums\Permission::AppSettingsUpdate->value)" />
                        <flux:error name="values.{{ $key }}" />
                    </flux:field>
                </div>
            @endforeach
        </div>

        <p class="mt-6 border-t border-[#eef1f4] pt-4 text-xs text-[#8a9298]">{{ __('ui.settings_secrets_note') }}</p>
    </div>
</div>
