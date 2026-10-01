<?php
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Enums\Permission;
use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\QueueWorkosSynchronization;
use App\Services\People\PeopleDirectory;
use Livewire\Component;
use Livewire\WithPagination;
new class extends Component {
 use WithPagination;
 public string $search='';public string $department_id='';public string $job_function_id='';public string $status='';public string $invitation_status='';public bool $no_access=false;
 public array $selected=[];public bool $confirmingInvitations=false;public bool $allPending=false;
 public function boot(): void {$this->authorize(Permission::PeopleView->value);}
 public function updated(string $property): void {if($property==='selected')return;if(in_array($property,['search','department_id','job_function_id','status','invitation_status','no_access'],true)){$this->resetPage();$this->selected=[];}}
 public function updatingPaginators(): void {$this->selected=[];}
 public function clearFilters(): void {$this->reset('search','department_id','job_function_id','status','invitation_status','no_access','selected');$this->resetPage();}
 public function clearSelection(): void {$this->selected=[];}
 public function synchronize(QueueWorkosSynchronization $action): void {$this->authorize(Permission::PeopleSyncWorkos->value);$action->handle();session()->flash('status',__('Synchronization queued. No invitations will be sent.'));}
 public function inviteSelected(): void {$this->authorize(Permission::PeopleInvite->value);$this->allPending=false;$this->confirmingInvitations=true;}
 public function inviteAllPending(): void {$this->authorize(Permission::PeopleInvite->value);$this->allPending=true;$this->confirmingInvitations=true;}
 public function queueInvitations(QueueWorkosInvitations $action): void {
 $this->authorize(Permission::PeopleInvite->value);
 $this->validate(['selected'=>'array|max:500','selected.*'=>'integer|min:1|distinct']);
 try{$count=$action->handle($this->selected,$this->allPending);$this->selected=[];$this->confirmingInvitations=false;session()->flash('status',trans_choice(':count invitation queued|:count invitations queued',$count,['count'=>$count]));}catch(\RuntimeException $e){$this->addError('invitations',$e->getMessage());}
 }
 public function with(PeopleDirectory $directory): array { $this->validate(['status'=>['nullable',Illuminate\Validation\Rule::enum(UserStatus::class)], 'invitation_status'=>['nullable',Illuminate\Validation\Rule::enum(WorkosInvitationState::class)], 'department_id'=>'nullable|integer', 'job_function_id'=>'nullable|integer']); return $directory->data($this->only(['search','department_id','job_function_id','status','invitation_status','no_access']));}
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('People')"
        :description="__('ui.people_page_description')">
        <div class="flex items-center gap-3">
            <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $people->total(), ['count' => $people->total()]) }}</span>
            @can(App\Enums\Permission::PeopleImport->value)
                <flux:button href="{{ route('people.import') }}" wire:navigate variant="primary" class="admin-primary-action">{{ __('Import people') }}</flux:button>
            @endcan

        </div>
    </x-page-hero>

    <section class="form-panel space-y-4 p-5 sm:p-6" @if($operationsActive) wire:poll.3s @endif aria-label="{{ __('WorkOS invitations') }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="detail-card-title">{{ __('WorkOS invitations') }}</h2><p class="text-sm text-secondary">{{ __('Synchronization checks this company without sending emails.') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @can(App\Enums\Permission::PeopleSyncWorkos->value)
                    <flux:button wire:click="synchronize" wire:loading.attr="disabled" wire:target="synchronize" :disabled="$latestSync?->status->isOpen() ?? false" variant="ghost">{{ __('Synchronize WorkOS') }}</flux:button>
                @endcan
                @can(App\Enums\Permission::PeopleInvite->value)
                    <flux:button wire:click="inviteAllPending" :disabled="$pendingInvitationsCount === 0" wire:loading.attr="disabled" variant="ghost">{{ __('Invite all pending in this company (:count)', ['count'=>$pendingInvitationsCount]) }}</flux:button>
                    @if(count($selected)>0)
                        <flux:button wire:click="inviteSelected" wire:loading.attr="disabled" variant="primary">{{ __('Send invitations to selected (:count)', ['count'=>count($selected)]) }}</flux:button>
                        <flux:button wire:click="clearSelection" variant="ghost">{{ __('Clear selection') }}</flux:button>
                    @endif
                @endcan
            </div>
        </div>
        <p class="text-sm text-secondary">{{ __('Last successful synchronization') }}: {{ $lastSuccessfulSync?->finished_at?->locale(app()->getLocale())->translatedFormat('M j, Y H:i') ?? __('Not synchronized yet') }}</p>
        @if($latestSync)
            <div role="status" aria-live="polite" class="text-sm text-secondary">
                <strong>{{ __('Synchronization') }}: {{ $latestSync->status->label() }}</strong>
                <span>{{ __('Processed :processed of :total', ['processed'=>$latestSync->processed,'total'=>$latestSync->total]) }}</span>
                <span>{{ __('Successful :success, skipped :skipped, failed :failed', ['success'=>$latestSync->succeeded,'skipped'=>$latestSync->skipped,'failed'=>$latestSync->failed]) }}</span>
                @if($latestSync->reason)<p>{{ App\Services\People\PeopleDirectory::reason($latestSync->reason) }}</p>@endif
            </div>
        @endif
        @if($pendingInvitationsCount===0)<p class="text-sm text-secondary">{{ __('No eligible invitation candidates in this company.') }}</p>@endif
        @if($invitationAttempts->isNotEmpty())
            <div class="space-y-1 text-sm" role="status" aria-live="polite"><h3 class="font-semibold">{{ __('Invitation outcomes') }}</h3>
                @foreach($invitationAttempts as $attempt)<p>{{ $attemptNames[$attempt->person_id] ?? __('Person') }}: {{ $attempt->status->label() }}@if($attempt->reason) — {{ App\Services\People\PeopleDirectory::reason($attempt->reason) }}@endif</p>@endforeach
            </div>
        @endif
    </section>
    <flux:modal wire:model="confirmingInvitations" class="max-w-lg">
        <div class="space-y-5"><flux:heading size="lg">{{ __('Confirm invitation recovery') }}</flux:heading>
            <p>{{ $allPending ? __('This action covers eligible people in this company, regardless of the current filters.') : __('This action covers :count selected people.', ['count'=>count($selected)]) }}</p>
            <p>{{ __('Pending invitations are resent. Expired or missing invitations receive a new invitation. Verification may skip recipients who already accepted, are current members or cannot access this company.') }}</p>
            @if(!$allPending)<p>{{ __('Selected revoked invitations will receive a new invitation after verification.') }}</p>@endif
            <div class="flex justify-end gap-2"><flux:button wire:click="$set('confirmingInvitations', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button wire:click="queueInvitations" wire:loading.attr="disabled" variant="primary">{{ __('Queue invitations') }}</flux:button></div>
        </div>
    </flux:modal>
    <x-status-message />
    @error('invitations') <flux:callout variant="danger" :heading="$message" /> @enderror

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <flux:input wire:model.live.debounce.400ms="search" class="admin-control" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Name, email or employee ID')" />
            <flux:select wire:model.live="department_id" class="admin-control" :label="__('Department')">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="job_function_id" class="admin-control" :label="__('Job function')">
                <option value="">{{ __('All job functions') }}</option>
                @foreach ($jobFunctions as $jobFunction)
                    <option value="{{ $jobFunction->id }}">{{ $jobFunction->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" class="admin-control" :label="__('Person status')">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (UserStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="invitation_status" class="admin-control" :label="__('Invitation status')"><option value="">{{ __('All invitation statuses') }}</option>@foreach(WorkosInvitationState::cases() as $state)<option value="{{ $state->value }}">{{ $state->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="no_access" class="admin-control" :label="__('Oceanix access')"><option value="0">{{ __('All access states') }}</option><option value="1">{{ __('No recorded access') }}</option></flux:select>
        </div>
    </div>

    @if ($people->isEmpty())
        <x-empty-state
            icon="user-group"
            :title="__('No people match these filters')"
            :description="__('ui.no_people_help')" />
        <flux:button wire:click="clearFilters" variant="ghost">{{ __('Clear filters') }}</flux:button>
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        @can(App\Enums\Permission::PeopleInvite->value)<th class="w-12"><span class="sr-only">{{ __('Select') }}</span></th>@endcan
                        <th>{{ __('Employee') }}</th>
                        <th>{{ __('Department') }}</th>
                        <th>{{ __('Job function') }}</th>
                        <th>{{ __('Person status') }}</th>
                        <th>{{ __('Invitation status') }}</th><th>{{ __('Oceanix access') }}</th>
                        <th class="text-right">{{ __('Open training') }}</th>
                        <th class="text-right">{{ __('Overdue training') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr class="border-t">
                            @can(App\Enums\Permission::PeopleInvite->value)
                                <td><flux:checkbox wire:model.live="selected" value="{{ $person->id }}" :disabled="! $person->status->canAccessTenant() || $person->workos_active_membership || $person->invitation_state === WorkosInvitationState::Accepted" :aria-label="__('Select :name', ['name' => $person->name])" /></td>
                            @endcan
                            <td>
                                <a href="{{ route('people.show', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'user' => $person]) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $person->name }}</a>
                                <span class="block text-xs text-[#8a9298]">{{ $person->email }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $person->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $person->jobFunctions->pluck('name')->join(', ') ?: '—' }}</td>
                            <td><span class="status-pill {{ $person->status->pillModifier() }}">{{ $person->status->label() }}</span></td>
                            <td>
                                @php($invitationState=$person->invitation_state ?? WorkosInvitationState::Unverified)
                                <span class="status-pill {{ $invitationState->pillModifier() }}">{{ $invitationState->label() }}</span>
                                @if($person->invitation_verified_at)<span class="block text-xs text-muted">{{ __('Verified :date',['date'=>$person->invitation_verified_at->locale(app()->getLocale())->translatedFormat('M j, Y H:i')]) }}</span>@endif
                                @if($person->invitation_check_error)<span class="block text-xs text-negative">{{ __('Latest check failed') }}</span>@endif
                            </td>
                            <td class="text-xs text-secondary">{{ $person->first_access_at?->locale(app()->getLocale())->translatedFormat('M j, Y H:i') ?? __('No recorded access') }}</td>
                            <td class="text-right font-bold text-[#5f6a71]">{{ $person->open_assignments_count }}</td>
                            <td class="text-right font-bold {{ $person->overdue_assignments_count > 0 ? 'text-[#b23a3a]' : 'text-[#8a9298]' }}">{{ $person->overdue_assignments_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $people->links() }}</div>
    @endif
</div>
