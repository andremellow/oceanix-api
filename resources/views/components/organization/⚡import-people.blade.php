<?php

use App\Actions\People\ImportPeople;
use App\Actions\People\QueueWorkosInvitations;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\JobFunction;
use App\Services\People\PeopleImportPreview;
use App\Services\People\PeopleSpreadsheetParser;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $spreadsheet;

    public array $rows = [];

    public array $previewErrors = [];

    public array $jobFunctionTerms = [];

    public array $departmentTerms = [];

    public ?array $result = null;

    public bool $queueInvitesAfterImport = false;

    public function preview(PeopleSpreadsheetParser $parser, PeopleImportPreview $preview): void
    {
        Gate::authorize(Permission::PeopleImport->value);

        $this->validate([
            'spreadsheet' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        try {
            $prepared = $preview->prepare($parser->parse($this->spreadsheet->getRealPath()));
        } catch (Throwable $exception) {
            $this->addError('spreadsheet', $exception->getMessage());

            return;
        }

        $this->rows = $prepared['rows'];
        $this->previewErrors = $prepared['errors'];
        $this->jobFunctionTerms = $prepared['job_functions'];
        $this->departmentTerms = $prepared['departments'];
        $this->result = null;
    }

    public function import(ImportPeople $action, QueueWorkosInvitations $invitations): void
    {
        Gate::authorize(Permission::PeopleImport->value);
        abort_if($this->rows === [] || $this->previewErrors !== [], 422);

        $jobFunctionMappings = collect($this->jobFunctionTerms)
            ->mapWithKeys(fn (array $term): array => [$term['value'] => (string) $term['selected']])
            ->all();
        $departmentMappings = collect($this->departmentTerms)
            ->mapWithKeys(fn (array $term): array => [$term['value'] => (string) $term['selected']])
            ->all();

        $this->result = $action->handle($this->rows, $jobFunctionMappings, $departmentMappings);

        if ($this->queueInvitesAfterImport) {
            $this->result['invitations_queued'] = $invitations->handle(allPending: true);
        }

        $this->reset(['spreadsheet', 'rows', 'previewErrors', 'jobFunctionTerms', 'departmentTerms']);
    }

    public function with(): array
    {
        return [
            'existingJobFunctions' => JobFunction::query()->orderBy('name')->get(),
            'existingDepartments' => Department::query()->orderBy('name')->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('Import people')"
        :description="__('Upload a spreadsheet with name, email, job function and department in columns A through D. The first row is always ignored.')">
        <flux:button href="{{ route('people.index') }}" wire:navigate variant="ghost">{{ __('Back to people') }}</flux:button>
    </x-page-hero>

    @if ($result)
        <flux:callout variant="success" :heading="__('Import completed')">
            {{ __(':created people created, :existing already existed, :functions job functions created and :departments departments created.', [
                'created' => $result['created'],
                'existing' => $result['existing'],
                'functions' => $result['job_functions_created'],
                'departments' => $result['departments_created'],
            ]) }}
            @if (isset($result['invitations_queued']))
                {{ trans_choice(':count invitation queued|:count invitations queued', $result['invitations_queued'], ['count' => $result['invitations_queued']]) }}
            @endif
        </flux:callout>
    @endif

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <h2 class="text-base font-bold text-[#262d33]">{{ __('Spreadsheet') }}</h2>
        <p class="mt-1 text-sm text-[#707a80]">{{ __('Column A: name. Column B: email. Column C: job function. Column D: department. Blank rows are ignored.') }}</p>

        <form wire:submit="preview" class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end">
            <flux:input type="file" wire:model="spreadsheet" accept=".xlsx" :label="__('Excel file')" class="min-w-0 flex-1" />
            <flux:button type="submit" variant="primary" class="admin-primary-action">
                <span wire:loading.remove wire:target="preview">{{ __('Review import') }}</span>
                <span wire:loading wire:target="preview">{{ __('Reading…') }}</span>
            </flux:button>
        </form>
    </div>

    @if ($previewErrors)
        <flux:callout variant="danger" :heading="__('Fix the spreadsheet before importing')">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($previewErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    @if ($rows)
        <div class="grid gap-5 xl:grid-cols-2">
            <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-bold text-[#262d33]">{{ __('Job function reconciliation') }}</h2>
                    <span class="status-pill status-pill--accent">{{ count($jobFunctionTerms) }}</span>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($jobFunctionTerms as $index => $term)
                        <div class="rounded-2xl border border-[#e1e6e9] bg-white p-4">
                            <div class="mb-2 flex justify-between gap-3 text-sm">
                                <span class="font-semibold text-[#262d33]">{{ $term['value'] }}</span>
                                <span class="text-[#8a9298]">{{ $term['count'] }}</span>
                            </div>
                            <flux:select wire:model="jobFunctionTerms.{{ $index }}.selected" :label="__('Import as')">
                                <option value="create">{{ __('Create a new job function') }}</option>
                                @foreach ($existingJobFunctions as $jobFunction)
                                    <option value="{{ $jobFunction->id }}">{{ __('Map to: :name', ['name' => $jobFunction->name]) }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    @empty
                        <p class="text-sm text-[#707a80]">{{ __('No job functions were provided.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-bold text-[#262d33]">{{ __('Department reconciliation') }}</h2>
                    <span class="status-pill status-pill--accent">{{ count($departmentTerms) }}</span>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($departmentTerms as $index => $term)
                        <div class="rounded-2xl border border-[#e1e6e9] bg-white p-4">
                            <div class="mb-2 flex justify-between gap-3 text-sm">
                                <span class="font-semibold text-[#262d33]">{{ $term['value'] }}</span>
                                <span class="text-[#8a9298]">{{ $term['count'] }}</span>
                            </div>
                            <flux:select wire:model="departmentTerms.{{ $index }}.selected" :label="__('Import as')">
                                <option value="create">{{ __('Create a new department') }}</option>
                                @foreach ($existingDepartments as $department)
                                    <option value="{{ $department->id }}">{{ __('Map to: :name', ['name' => $department->name]) }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    @empty
                        <p class="text-sm text-[#707a80]">{{ __('No departments were provided.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="rounded-[20px] border border-[#dde3e7] bg-white p-5 shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)] sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-bold text-[#262d33]">{{ trans_choice(':count person ready to import|:count people ready to import', count($rows), ['count' => count($rows)]) }}</h2>
                    <p class="mt-1 text-sm text-[#707a80]">{{ __('Existing people are matched by email and are not duplicated.') }}</p>
                    @can(App\Enums\Permission::PeopleInvite->value)
                        <flux:checkbox wire:model="queueInvitesAfterImport" class="mt-3" :label="__('Queue WorkOS invitations after importing')" />
                    @endcan
                </div>
                <flux:button wire:click="import" variant="primary" class="admin-primary-action" :disabled="$previewErrors !== []">
                    <span wire:loading.remove wire:target="import">{{ __('Confirm import') }}</span>
                    <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
                </flux:button>
            </div>
        </div>
    @endif
</div>
