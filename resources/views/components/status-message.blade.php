@if (session('status'))
    <div
        x-data
        x-init="$nextTick(() => $flux.toast({ text: @js(session('status')), variant: 'success', duration: 5000 }))"
        class="hidden"
        aria-hidden="true"
    ></div>
@endif
