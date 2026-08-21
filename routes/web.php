<?php

use App\Actions\Platform\EnterCompany;
use App\Actions\Tenancy\SwitchCompany;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\WorkosController;
use App\Http\Controllers\CertificateDownloadController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\ComplianceExportController;
use App\Http\Controllers\DevVideoController;
use App\Http\Controllers\TrainingPlaybackController;
use App\Http\Middleware\EnsureUserCanAccessControlCenter;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsPlatformAdmin;
use App\Http\Middleware\IdentifyCompany;
use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return app(PlatformAccess::class)->account() !== null
        ? redirect()->route('platform.dashboard')
        : redirect()->route('login');
})->middleware(IdentifyCompany::class)->name('home');

// Public certificate verification. Exposes only validity, holder, course and dates —
// never employee id, department, answers or history. See docs/product-spec.md §17.
Route::get('/verify/{code?}', CertificateVerificationController::class)
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

Route::post('/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['en', 'pt_BR'], true), 404);
    session(['locale' => $locale]);

    return back();
})->name('locale.update');

// Local development video provider (see App\Services\Video\FakeVideoProvider). Signed,
// short-lived URLs, and the routes do not exist outside the local environment.
if (app()->environment('local')) {
    Route::post('/dev/videos/{asset}', [DevVideoController::class, 'store'])
        ->middleware('signed')
        ->name('dev.video.upload');
    Route::get('/dev/videos/{asset}', [DevVideoController::class, 'show'])
        ->middleware('signed')
        ->name('dev.video.play');
}

Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', 'auth.login')->name('login');
    Route::livewire('/login/{company:slug}', 'auth.login')
        ->middleware(IdentifyCompany::class)
        ->name('tenant.login');

    // Local-only bypass so the application can be opened before WorkOS is configured.
    // Registered only in the local environment and only for the single configured
    // address; it authenticates nobody else.
    if (app()->environment('local') && filled(config('oceanix.local_auth_email'))) {
        Route::get('/auth/local', function () {
            $email = strtolower((string) config('oceanix.local_auth_email'));
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => str($email)->before('@')->replace(['.', '_'], ' ')->title()->toString(),
                    'email_verified_at' => now(),
                    'status' => UserStatus::Active,
                ],
            );

            $account = Account::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $user->name,
                    'status' => 'active',
                ],
            );
            $account->forceFill([
                'is_platform_admin' => $account->is_platform_admin
                    || in_array($email, config('oceanix.platform_admin_emails', []), true),
            ])->save();
            $user->forceFill(['account_id' => $account->id])->save();

            $admin = Role::query()->where('key', 'admin')->firstOrFail();
            $user->roles()->syncWithoutDetaching($admin);

            Auth::login($user, remember: true);
            request()->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        })->middleware(IdentifyCompany::class)->name('auth.local');
    }

    Route::middleware([IdentifyCompany::class, 'throttle:auth'])->group(function (): void {
        Route::get('/auth/workos/redirect', [WorkosController::class, 'redirect'])->name('auth.workos.redirect');
        Route::get('/auth/workos/callback', [WorkosController::class, 'callback'])->name('auth.workos.callback');
    });
    Route::get('/auth/workos/platform/redirect', [WorkosController::class, 'platformRedirect'])
        ->middleware('throttle:auth')
        ->name('auth.workos.platform.redirect');
});

Route::prefix('platform')
    ->middleware(EnsureUserIsPlatformAdmin::class)
    ->group(function (): void {
        Route::livewire('/', 'platform.dashboard')->name('platform.dashboard');
        Route::livewire('/companies', 'platform.companies')->name('platform.companies');
        Route::livewire('/companies/{company}', 'platform.company')->name('platform.companies.show');
        Route::post('/companies/{company}/enter', function (Company $company, EnterCompany $action) {
            $action->handle($company);

            return redirect()->route('dashboard', ['company' => $company]);
        })->name('platform.companies.enter');
        Route::post('/logout', function () {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('login');
        })->name('platform.logout');
    });

// Keep existing bookmarks and notification links useful while tenant URLs migrate to
// /c/{slug}. These endpoints only redirect after the active company has been identified.
Route::middleware([IdentifyCompany::class, 'auth'])->group(function (): void {
    $legacyRoutes = [
        '/dashboard' => 'dashboard',
        '/my-training' => 'my-training',
        '/my-training/{assignment}' => 'my-training.show',
        '/my-training/{assignment}/lessons/{lesson}' => 'my-training.lesson',
        '/certificates/{certificate}/download' => 'certificates.download',
        '/courses' => 'courses.index',
        '/courses/{course}' => 'courses.show',
        '/courses/{course}/editor' => 'courses.editor',
        '/requirements' => 'requirements.index',
        '/assignments' => 'assignments.index',
        '/assignments/export' => 'assignments.export',
        '/assignments/{assignment}' => 'assignments.show',
        '/certificates' => 'certificates.index',
        '/people' => 'people.index',
        '/people/import' => 'people.import',
        '/people/{user}' => 'people.show',
        '/departments' => 'departments.index',
        '/job-functions' => 'job-functions.index',
        '/settings' => 'settings',
        '/audit-log' => 'audit-log',
        '/admin/access-profiles' => 'admin.access-profiles',
        '/admin/access-profiles/{role}' => 'admin.access-profiles.show',
    ];

    foreach ($legacyRoutes as $uri => $target) {
        Route::get($uri, function () use ($target) {
            $parameters = request()->route()->parameters();
            $parameters['company'] = app(TenantContext::class)->get();

            return redirect()->route($target, $parameters);
        })->name('legacy.'.str_replace(['/', '{', '}'], ['.', '', ''], trim($uri, '/')));
    }
});

Route::post('/switch-company/{targetCompany:slug}', function (Company $targetCompany, SwitchCompany $action) {
    $action->handle(request()->user(), $targetCompany);

    return redirect()->route('dashboard', ['company' => $targetCompany]);
})->middleware([IdentifyCompany::class, 'auth'])->name('company.switch');

Route::get('/c/{company:slug}', function (Company $company) {
    return Auth::check()
        ? redirect()->route('dashboard', ['company' => $company])
        : redirect()->route('tenant.login', ['company' => $company]);
})->middleware(IdentifyCompany::class)->name('company.entry');

Route::prefix('c/{company:slug}')
    ->middleware([IdentifyCompany::class, 'auth'])
    ->group(function (): void {
        // Every authenticated person lands here. The component renders the compliance overview
        // for operators and the personal training board for everyone else.
        Route::livewire('/dashboard', 'dashboard')->name('dashboard');
        Route::livewire('/my-training', 'training.my-training')->name('my-training');
        Route::livewire('/my-training/{assignment}', 'training.assignment')->name('my-training.show');
        Route::get('/certificates/{certificate}/download', CertificateDownloadController::class)
            ->name('certificates.download');
        Route::livewire('/my-training/{assignment}/lessons/{lesson}', 'training.lesson')->name('my-training.lesson');

        // Playback authorization and event ingestion for the player. Both re-authorize the
        // assignment on every call and are rate limited: they are the two endpoints a client
        // touches most.
        Route::post('/my-training/{assignment}/lessons/{lesson}/playback', [TrainingPlaybackController::class, 'authorizePlayback'])
            ->middleware('throttle:playback')
            ->name('my-training.playback');
        Route::post('/my-training/{assignment}/lessons/{lesson}/events', [TrainingPlaybackController::class, 'ingest'])
            ->middleware('throttle:compliance-events')
            ->name('my-training.events');

        Route::middleware(EnsureUserCanAccessControlCenter::class)->group(function (): void {
            Route::livewire('/courses', 'courses.index')
                ->middleware(EnsureUserHasPermission::class.':courses.view')
                ->name('courses.index');
            Route::livewire('/courses/{course}', 'courses.show')
                ->middleware(EnsureUserHasPermission::class.':courses.view')
                ->name('courses.show');
            Route::livewire('/courses/{course}/editor', 'courses.editor')
                ->middleware(EnsureUserHasPermission::class.':courses.update')
                ->name('courses.editor');

            Route::livewire('/requirements', 'compliance.requirements')
                ->middleware(EnsureUserHasPermission::class.':training-requirements.view')
                ->name('requirements.index');

            Route::livewire('/assignments', 'compliance.assignments')
                ->middleware(EnsureUserHasPermission::class.':assignments.view')
                ->name('assignments.index');
            Route::get('/assignments/export', ComplianceExportController::class)
                ->middleware(EnsureUserHasPermission::class.':compliance-reports.export')
                ->name('assignments.export');
            Route::livewire('/assignments/{assignment}', 'compliance.assignment')
                ->middleware(EnsureUserHasPermission::class.':assignments.view')
                ->name('assignments.show');

            Route::livewire('/certificates', 'compliance.certificates')
                ->middleware(EnsureUserHasPermission::class.':certificates.view')
                ->name('certificates.index');

            Route::livewire('/people', 'organization.people')
                ->middleware(EnsureUserHasPermission::class.':people.view')
                ->name('people.index');
            Route::livewire('/people/import', 'organization.import-people')
                ->middleware(EnsureUserHasPermission::class.':people.import')
                ->name('people.import');
            Route::livewire('/people/{user}', 'organization.person')
                ->middleware(EnsureUserHasPermission::class.':people.view')
                ->name('people.show');

            Route::livewire('/departments', 'organization.departments')
                ->middleware(EnsureUserHasPermission::class.':departments.view')
                ->name('departments.index');

            Route::livewire('/job-functions', 'organization.job-functions')
                ->middleware(EnsureUserHasPermission::class.':job-functions.view')
                ->name('job-functions.index');

            Route::livewire('/settings', 'admin.settings')
                ->middleware(EnsureUserHasPermission::class.':app-settings.view')
                ->name('settings');
            Route::livewire('/audit-log', 'admin.audit-log')
                ->middleware(EnsureUserHasPermission::class.':audit-logs.view')
                ->name('audit-log');
        });

        Route::middleware([EnsureUserCanAccessControlCenter::class, EnsureUserIsAdmin::class])
            ->prefix('admin')
            ->group(function (): void {
                Route::livewire('/access-profiles', 'admin.access-profiles')->name('admin.access-profiles');
                Route::livewire('/access-profiles/{role}', 'admin.access-profile')->name('admin.access-profiles.show');
            });

        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('login');
        })->name('logout');
    });
