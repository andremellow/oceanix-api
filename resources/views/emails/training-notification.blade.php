<x-mail::message>
# {{ __('ui.greeting', ['name' => str($name)->before(' ')]) }}

{{ $type->label() }} — **{{ $assignment?->course->title }}**

@if ($assignment?->due_at)
{{ $type->isEscalation()
    ? __('ui.mail_was_due', ['date' => $assignment->due_at->locale(app()->getLocale())->translatedFormat('j F Y')])
    : __('ui.mail_due_on', ['date' => $assignment->due_at->locale(app()->getLocale())->translatedFormat('j F Y')]) }}
@endif

<x-mail::button :url="$url">
{{ __('ui.mail_open_training') }}
</x-mail::button>

{{ __('ui.mail_footer') }}
</x-mail::message>
