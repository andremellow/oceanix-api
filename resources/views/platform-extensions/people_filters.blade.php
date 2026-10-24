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
