@can(App\Enums\Permission::PeopleImport->value)
                <flux:button href="{{ route('people.import') }}" wire:navigate variant="primary" class="admin-primary-action">{{ __('Import people') }}</flux:button>
            @endcan
