<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f2f5f7">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="icon" href="{{ asset('images/oceanix-mark.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <script>
        // The authenticated product shell is intentionally light-only. Keep Flux from mixing
        // OS dark-mode text with the light application canvas.
        window.Flux.applyAppearance('light');
    </script>
</head>
<body class="min-h-screen bg-[#f2f5f7] text-[#1f262b] antialiased">
    @auth
        @php($user = auth()->user())
        @php($operator = $user->canAccessControlCenter())
        <div class="min-h-screen lg:grid lg:grid-cols-[256px_minmax(0,1fr)]">
            <input id="mobile-navigation" type="checkbox" class="peer sr-only">

            <label for="mobile-navigation" class="fixed inset-0 z-30 hidden bg-zinc-950/25 backdrop-blur-sm peer-checked:block lg:hidden" aria-label="{{ __('ui.close_menu') }}"></label>

            <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-[#e2e7ea] bg-[#f9fbfc] px-4 py-5 transition-all duration-200 peer-checked:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
                <div class="flex items-center justify-between px-2">
                    <a href="{{ route('dashboard') }}" wire:navigate class="group flex items-center gap-3">
                        <span class="grid size-10 place-items-center overflow-hidden rounded-xl shadow-sm transition-transform group-hover:-rotate-3">
                            <img src="{{ asset('images/oceanix-mark.svg') }}" alt="" class="size-10">
                        </span>
                        <span>
                            <span class="block text-[15px] font-bold tracking-tight">Oceanix</span>
                            <span class="block text-[10px] font-semibold uppercase tracking-[.18em] text-[#8a9298]">{{ __('ui.control_center') }}</span>
                        </span>
                    </a>
                    <label for="mobile-navigation" class="grid size-8 cursor-pointer place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100 lg:hidden" aria-label="{{ __('ui.close_menu') }}">
                        <flux:icon.x-mark class="size-5" />
                    </label>
                </div>

                <nav class="mt-9 min-h-0 flex-1 space-y-7 overflow-y-auto overscroll-contain pb-4 pr-1" aria-label="{{ __('ui.main_navigation') }}">
                    <div>
                        <p class="px-3 text-[10px] font-bold uppercase tracking-[.16em] text-[#9ea6ac]">{{ __('ui.overview') }}</p>
                        <div class="mt-2 space-y-1">
                            <a href="{{ route('dashboard') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                                <flux:icon.home class="size-[18px]" />
                                <span>{{ __('Dashboard') }}</span>
                            </a>
                            <a href="{{ route('my-training') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('my-training*') ? 'is-active' : '' }}">
                                <flux:icon.academic-cap class="size-[18px]" />
                                <span>{{ __('ui.my_training') }}</span>
                            </a>
                        </div>
                    </div>

                    {{-- Navigation reflects capability, never only labels. The route middleware
                         still enforces the same permission: hiding a link is usability. --}}
                    @if ($user->can(\App\Enums\Permission::CoursesView->value)
                        || $user->can(\App\Enums\Permission::RequirementsView->value)
                        || $user->can(\App\Enums\Permission::AssignmentsView->value)
                        || $user->can(\App\Enums\Permission::CertificatesView->value))
                        <div>
                            <p class="px-3 text-[10px] font-bold uppercase tracking-[.16em] text-[#9ea6ac]">{{ __('ui.compliance') }}</p>
                            <div class="mt-2 space-y-1">
                                @can(\App\Enums\Permission::CoursesView->value)
                                    <a href="{{ route('courses.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('courses.*') ? 'is-active' : '' }}">
                                        <flux:icon.book-open class="size-[18px]" />
                                        <span>{{ __('Courses') }}</span>
                                    </a>
                                @endcan
                                @can(\App\Enums\Permission::RequirementsView->value)
                                    <a href="{{ route('requirements.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('requirements.*') ? 'is-active' : '' }}">
                                        <flux:icon.clipboard-document-check class="size-[18px]" />
                                        <span>{{ __('Requirements') }}</span>
                                    </a>
                                @endcan
                                @can(\App\Enums\Permission::AssignmentsView->value)
                                    <a href="{{ route('assignments.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('assignments.*') ? 'is-active' : '' }}">
                                        <flux:icon.rectangle-stack class="size-[18px]" />
                                        <span>{{ __('Assignments') }}</span>
                                    </a>
                                @endcan
                                @can(\App\Enums\Permission::CertificatesView->value)
                                    <a href="{{ route('certificates.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('certificates.*') ? 'is-active' : '' }}">
                                        <flux:icon.document-check class="size-[18px]" />
                                        <span>{{ __('Certificates') }}</span>
                                    </a>
                                @endcan
                            </div>
                        </div>
                    @endif

                    @if ($user->can(\App\Enums\Permission::PeopleView->value)
                        || $user->can(\App\Enums\Permission::DepartmentsView->value)
                        || $user->can(\App\Enums\Permission::JobFunctionsView->value))
                        <div>
                            <p class="px-3 text-[10px] font-bold uppercase tracking-[.16em] text-[#9ea6ac]">{{ __('ui.organization') }}</p>
                            <div class="mt-2 space-y-1">
                                @can(\App\Enums\Permission::PeopleView->value)
                                    <a href="{{ route('people.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('people.*') ? 'is-active' : '' }}">
                                        <flux:icon.user-group class="size-[18px]" />
                                        <span>{{ __('People') }}</span>
                                    </a>
                                @endcan
                                @can(\App\Enums\Permission::DepartmentsView->value)
                                    <a href="{{ route('departments.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('departments.*') ? 'is-active' : '' }}">
                                        <flux:icon.building-office-2 class="size-[18px]" />
                                        <span>{{ __('Departments') }}</span>
                                    </a>
                                @endcan
                                @can(\App\Enums\Permission::JobFunctionsView->value)
                                    <a href="{{ route('job-functions.index') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('job-functions.*') ? 'is-active' : '' }}">
                                        <flux:icon.identification class="size-[18px]" />
                                        <span>{{ __('Job functions') }}</span>
                                    </a>
                                @endcan
                            </div>
                        </div>
                    @endif

                    @if ($user->isAdmin() || $user->can(\App\Enums\Permission::AuditLogsView->value))
                        <div>
                            <p class="px-3 text-[10px] font-bold uppercase tracking-[.16em] text-[#9ea6ac]">{{ __('ui.administration') }}</p>
                            <div class="mt-2 space-y-1">
                                @if ($user->isAdmin())
                                    <a href="{{ route('admin.users') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('admin.users*') ? 'is-active' : '' }}">
                                        <flux:icon.users class="size-[18px]" />
                                        <span>{{ __('ui.users') }}</span>
                                    </a>
                                    <a href="{{ route('admin.access-profiles') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('admin.access-profiles*') ? 'is-active' : '' }}">
                                        <flux:icon.key class="size-[18px]" />
                                        <span>{{ __('ui.access_profiles') }}</span>
                                    </a>
                                @endif
                                @can(\App\Enums\Permission::AuditLogsView->value)
                                    <a href="{{ route('audit-log') }}" wire:navigate class="saas-nav-item {{ request()->routeIs('audit-log') ? 'is-active' : '' }}">
                                        <flux:icon.shield-check class="size-[18px]" />
                                        <span>{{ __('Audit log') }}</span>
                                    </a>
                                @endcan
                            </div>
                        </div>
                    @endif
                </nav>

                <div class="mt-4 shrink-0 rounded-2xl border border-[#d9e5ea] bg-[#eef6f9] p-3.5">
                    <div class="flex items-center gap-3">
                        <div class="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-sm font-bold text-[#1c6b84] shadow-sm ring-1 ring-[#d5e4ea]">
                            {{ $user->initial() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-[11px] text-[#6f797f]">{{ $user->email }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="grid size-8 place-items-center rounded-lg text-[#6f797f] hover:bg-white hover:text-[#1f262b]" aria-label="{{ __('ui.logout') }}">
                                <flux:icon.arrow-right-start-on-rectangle class="size-[17px]" />
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            <div class="min-w-0">
                <header class="sticky top-0 z-20 flex h-[72px] items-center border-b border-[#e4e8eb]/90 bg-[#f2f5f7]/90 px-4 backdrop-blur-xl sm:px-7 lg:px-10">
                    <label for="mobile-navigation" class="mr-3 grid size-10 cursor-pointer place-items-center rounded-xl border border-[#dce1e5] bg-white text-zinc-600 shadow-sm lg:hidden" aria-label="{{ __('ui.open_menu') }}">
                        <flux:icon.bars-3 class="size-5" />
                    </label>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[.16em] text-[#8f979d]">{{ now()->locale(app()->getLocale())->translatedFormat(__('ui.date_format')) }}</p>
                        <p class="truncate text-sm font-semibold text-[#394147]">{{ $operator ? __('ui.ready_operator') : __('ui.ready_employee') }}</p>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span class="hidden items-center gap-2 rounded-full border border-[#d5e4ea] bg-[#eaf4f8] px-3 py-1.5 text-xs font-semibold text-[#286d84] sm:flex">
                            <span class="size-1.5 rounded-full bg-[#3e9cb8] shadow-[0_0_0_3px_rgba(62,156,184,.15)]"></span>
                            {{ __('ui.system_operational') }}
                        </span>
                        <form method="POST" action="{{ route('locale.update', app()->getLocale() === 'pt_BR' ? 'en' : 'pt_BR') }}">
                            @csrf
                            <button type="submit" class="grid size-10 place-items-center rounded-full border border-[#dce1e5] bg-white text-[#566067] shadow-sm transition hover:border-[#bcc5cb] hover:bg-[#f8fafb]" aria-label="{{ __('ui.switch_language') }}" title="{{ __('ui.switch_language') }}">
                                <flux:icon.language class="size-[18px]" />
                            </button>
                        </form>
                        <div class="grid size-10 place-items-center rounded-full border border-[#dce1e5] bg-white text-sm font-bold text-[#464f55] shadow-sm">
                            {{ $user->initial() }}
                        </div>
                    </div>
                </header>

                <main class="mx-auto w-full max-w-[1480px] px-4 py-7 sm:px-7 lg:px-10 lg:py-9">
                    {{ $slot }}
                </main>
            </div>
        </div>
    @else
        {{ $slot }}
    @endauth

    @fluxScripts
</body>
</html>
