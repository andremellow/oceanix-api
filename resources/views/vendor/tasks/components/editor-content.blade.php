<div class="space-y-7">
    <section class="admin-hero"><p class="admin-kicker">{{ __('Task detail') }}</p><h1 class="mt-2 text-3xl font-bold">{{ $task->title }}</h1><p class="mt-2 text-sm text-[#626a61]">{{ $task->status->label() }} · {{ $task->priority->label() }}</p></section>
    @if(session('task-saved'))<div role="status" class="rounded-xl bg-[#edf5e5] p-4 text-sm font-semibold text-[#398344]">{{ __('Task saved.') }}</div>@endif
    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]"><section class="saas-feature-card p-5 sm:p-6"><form wire:submit="save" class="space-y-4"><flux:input wire:model="title" :label="__('Title')"/><div class="grid gap-4 sm:grid-cols-2"><flux:select wire:model="taskTypeId" :label="__('Type')">@foreach($this->types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</flux:select><flux:select wire:model="priority" :label="__('Priority')">@foreach(\Andremellow\Tasks\Enums\TaskPriority::cases() as $value)<option value="{{ $value->value }}">{{ $value->label() }}</option>@endforeach</flux:select><flux:input type="date" wire:model="dueDate" :label="__('Due date')"/><flux:select wire:model="status" wire:change="move" :label="__('Status')">@foreach(\Andremellow\Tasks\Enums\TaskStatus::cases() as $value)<option value="{{ $value->value }}">{{ $value->label() }}</option>@endforeach</flux:select></div><x-tasks::markdown-editor model="description" :value="$description"/><div class="grid gap-2 sm:grid-cols-2">@foreach($this->tags as $tag)<flux:checkbox wire:model="tagIds" value="{{ $tag->id }}" :label="$tag->name"/>@endforeach</div>@can('update', $task)<flux:button type="submit" variant="primary">{{ __('Save task') }}</flux:button>@endcan</form></section>
        <aside class="space-y-5"><section class="saas-feature-card p-5"><h2 class="font-bold">{{ __('Assignment') }}</h2>@can('assign', $task)<flux:select wire:model="assigneeId" wire:change="assign" :label="__('Assignee')"><option value="">{{ __('Unassigned') }}</option>@if($task->assignee && !$this->assignees->contains('id', $task->assignee_id))<option value="{{ $task->assignee_id }}">{{ $task->assignee->name }} ({{ __('ineligible') }})</option>@endif @foreach($this->assignees as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</flux:select>@else<p class="mt-2 text-sm">{{ $task->assignee?->name ?? __('Unassigned') }}</p>@endcan</section>
        <section class="saas-feature-card p-5">
            <h2 class="font-bold">{{ __('Task media') }}</h2>
            <p class="mt-1 text-xs leading-5 text-[#737a72]">{{ __('Add screenshots, screen recordings, or supporting files. Media belongs only to this task.') }}</p>

            @if($this->images->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3">
                    @foreach($this->images as $media)
                        <article class="group relative overflow-hidden rounded-xl border border-[#dfe3dc] bg-[#f8faf6]">
                            <a href="{{ route(config('tasks.web.name').'attachments.show', [$task, $media]) }}" target="_blank" rel="noopener" aria-label="{{ __('Open :name', ['name' => $media->name]) }}">
                                <img src="{{ route(config('tasks.web.name').'attachments.show', [$task, $media]) }}" alt="{{ $media->name }}" loading="lazy" class="aspect-video w-full object-cover">
                            </a>
                            <div class="flex items-center gap-2 p-2 text-xs"><span class="min-w-0 flex-1 truncate">{{ $media->name }}</span>@can('manageAttachments', $task)<button type="button" wire:click="removeAttachment({{ $media->id }})" wire:confirm="{{ __('Remove this image?') }}" class="font-semibold text-[#c64242]">{{ __('Remove') }}</button>@endcan</div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if($this->videos->isNotEmpty())
                <div class="mt-4 space-y-3">
                    @foreach($this->videos as $media)
                        <article class="overflow-hidden rounded-xl border border-[#dfe3dc] bg-black">
                            <video controls preload="metadata" playsinline class="aspect-video w-full" aria-label="{{ $media->name }}">
                                <source src="{{ route(config('tasks.web.name').'attachments.show', [$task, $media]) }}" type="{{ $media->mime_type }}">
                                {{ __('Your browser cannot play this video.') }}
                            </video>
                            <div class="flex items-center gap-2 bg-white p-2 text-xs"><span class="min-w-0 flex-1 truncate">{{ $media->name }}</span><a class="font-semibold text-[#4f7841]" href="{{ route(config('tasks.web.name').'attachments.download', [$task, $media]) }}">{{ __('Download') }}</a>@can('manageAttachments', $task)<button type="button" wire:click="removeAttachment({{ $media->id }})" wire:confirm="{{ __('Remove this video?') }}" class="font-semibold text-[#c64242]">{{ __('Remove') }}</button>@endcan</div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if($this->documents->isNotEmpty())
                <ul class="mt-4 space-y-2">
                    @foreach($this->documents as $media)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-[#e6e9e3] px-3 py-2 text-sm"><a class="min-w-0 flex-1 truncate text-[#4f7841]" href="{{ route(config('tasks.web.name').'attachments.download', [$task, $media]) }}">{{ $media->name }}</a>@can('manageAttachments', $task)<button type="button" wire:click="removeAttachment({{ $media->id }})" wire:confirm="{{ __('Remove this file?') }}" class="text-[#c64242]">{{ __('Remove') }}</button>@endcan</li>
                    @endforeach
                </ul>
            @endif

            @if($this->attachments->isEmpty())
                <p class="mt-4 rounded-xl border border-dashed border-[#dfe3dc] p-4 text-center text-sm text-[#737a72]">{{ __('No task media yet.') }}</p>
            @endif

            @can('manageAttachments', $task)
                <flux:button type="button" wire:click="$set('mediaUploadOpen', true)" variant="primary" class="mt-4 w-full">{{ __('Add media') }}</flux:button>
            @endcan
        </section>
        @can('delete', $task)<flux:button variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this task?') }}">{{ __('Delete task') }}</flux:button>@endcan</aside></div>
    <livewire:tasks::tasks.comments :task="$task" :key="'task-comments-'.$task->id" />

    <section class="saas-feature-card p-5"><h2 class="font-bold">{{ __('Change history') }}</h2><ol class="mt-4 space-y-3">@forelse($this->history as $change)<li class="border-l-2 border-[#cfe2e9] pl-4 text-sm"><strong>{{ __(str($change->operation)->headline()->toString()) }}</strong><span class="text-[#737a72]"> · {{ $change->actor?->name ?? __('Deleted user') }} · {{ $change->created_at->diffForHumans() }}</span></li>@empty<li class="text-sm text-[#737a72]">{{ __('No changes recorded yet.') }}</li>@endforelse</ol></section>

    @can('manageAttachments', $task)
        <flux:modal wire:model.self="mediaUploadOpen" class="max-w-xl">
            <form wire:submit="upload" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Add task media') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Upload screenshots, screen recordings, or supporting files. They will only be available from this task.') }}</flux:text>
                </div>

                <label class="block cursor-pointer rounded-2xl border border-dashed border-[#bfc8ba] bg-[#f8faf6] p-8 text-center hover:bg-[#f1f5ed]">
                    <span class="block text-sm font-semibold text-[#4f7841]">{{ __('Choose media from this device') }}</span>
                    <span class="mt-1 block text-xs text-[#737a72]">{{ __('Images, videos, and supporting documents') }}</span>
                    <input type="file" wire:model="uploads" accept="image/*,video/mp4,video/quicktime,video/x-m4v,video/webm,.pdf,.txt,.md,.csv,.doc,.docx,.odt,.xls,.xlsx,.ods" class="sr-only" aria-label="{{ __('Choose task media') }}">
                </label>

                @if($uploads !== [])
                    <ul class="space-y-2 rounded-xl border border-[#e6e9e3] p-3 text-sm">
                        @foreach($uploads as $upload)
                            <li class="flex items-center justify-between gap-3"><span class="min-w-0 truncate">{{ $upload->getClientOriginalName() }}</span><span class="shrink-0 text-xs text-[#737a72]">{{ number_format($upload->getSize() / 1048576, 1) }} MB</span></li>
                        @endforeach
                    </ul>
                @endif

                <p class="text-xs leading-5 text-[#737a72]">{{ __('Images up to :image MB, videos up to :video MB, and documents up to :document MB per file.', ['image' => round(config('tasks.image_max_kb', 20480) / 1024), 'video' => round(config('tasks.video_max_kb', 524288) / 1024), 'document' => round(config('tasks.attachment_max_kb', 10240) / 1024)]) }}</p>
                @error('uploads.*')<p class="text-sm font-semibold text-[#c64242]" role="alert">{{ $message }}</p>@enderror

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('mediaUploadOpen', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" :disabled="$uploads === []" wire:loading.attr="disabled" wire:target="uploads,upload">{{ __('Upload media') }}</flux:button>
                </div>
                <p wire:loading wire:target="uploads,upload" class="text-center text-xs text-[#737a72]">{{ __('Uploading… Keep this page open.') }}</p>
            </form>
        </flux:modal>
    @endcan
</div>
