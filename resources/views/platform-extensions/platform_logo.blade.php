@if (config('oceanix-platform.ui.logo'))
    <img src="{{ asset(config('oceanix-platform.ui.logo')) }}" alt="{{ config('app.name') }}" class="h-auto w-32 max-w-full">
@endif
