<?php
use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use Livewire\Attributes\Locked;
use Livewire\Component;
new class extends Component {
    #[Locked] public string $operationId;
    public string $name = '';
    public string $email = '';
    public ?string $outcome = null;
    public function boot(): void { $this->authorize(Permission::PeopleManage->value); }
    public function mount(): void { $this->operationId = (string) Illuminate\Support\Str::uuid(); }
    public function save(RegisterPerson $action): void {
        $this->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);
        $operation = $action->handle(auth()->user(), $this->operationId, $this->name, $this->email);
        $this->outcome = $operation->state;
        if ($operation->state === 'confirmed') { session()->flash('status', __('Person registered. No invitation was sent.')); $this->redirectRoute('people.show', ['user' => $operation->user_id]); }
    }
}; ?>
<div class="admin-page space-y-7"><x-page-hero :kicker="__('ui.organization')" :title="__('Register person')" :description="__('Registration waits for Account confirmation and does not send an invitation.')"><flux:button :href="route('people.index')" variant="ghost">{{ __('Back to people') }}</flux:button></x-page-hero><section class="detail-card"><form wire:submit="save" class="space-y-5"><flux:input id="account-person-name" wire:model="name" :label="__('Name')" required :disabled="$outcome !== null"/><flux:input id="account-person-email" wire:model="email" :label="__('Email')" type="email" required :disabled="$outcome !== null"/>@if($outcome)<p role="status" aria-live="polite">{{ __('Account has not confirmed this registration. Retry checks the same request; no invitation will be sent.') }}</p>@endif<flux:button id="account-person-save" type="submit" variant="primary" wire:loading.attr="disabled">{{ $outcome ? __('Check registration again') : __('Register person') }}</flux:button><span wire:loading role="status">{{ __('Waiting for Account confirmation…') }}</span></form></section></div>
