<?php

use App\Http\Controllers\Auth\WorkosController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Middleware\EnsureUserCanAccessControlCenter;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

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

Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', 'auth.login')->name('login');

    Route::middleware('throttle:auth')->group(function (): void {
        Route::get('/auth/workos/redirect', [WorkosController::class, 'redirect'])->name('auth.workos.redirect');
        Route::get('/auth/workos/callback', [WorkosController::class, 'callback'])->name('auth.workos.callback');
    });
});

Route::middleware('auth')->group(function (): void {
    // Every authenticated person lands here. The component renders the compliance overview
    // for operators and the personal training board for everyone else.
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/my-training', 'training.my-training')->name('my-training');
    Route::livewire('/my-training/{assignment}', 'training.assignment')->name('my-training.show');

    Route::middleware(EnsureUserCanAccessControlCenter::class)->group(function (): void {
        Route::livewire('/courses', 'courses.index')
            ->middleware(EnsureUserHasPermission::class.':courses.view')
            ->name('courses.index');
        Route::livewire('/courses/{course}', 'courses.show')
            ->middleware(EnsureUserHasPermission::class.':courses.view')
            ->name('courses.show');

        Route::livewire('/requirements', 'compliance.requirements')
            ->middleware(EnsureUserHasPermission::class.':training-requirements.view')
            ->name('requirements.index');

        Route::livewire('/assignments', 'compliance.assignments')
            ->middleware(EnsureUserHasPermission::class.':assignments.view')
            ->name('assignments.index');

        Route::livewire('/certificates', 'compliance.certificates')
            ->middleware(EnsureUserHasPermission::class.':certificates.view')
            ->name('certificates.index');

        Route::livewire('/people', 'organization.people')
            ->middleware(EnsureUserHasPermission::class.':people.view')
            ->name('people.index');
        Route::livewire('/people/{user}', 'organization.person')
            ->middleware(EnsureUserHasPermission::class.':people.view')
            ->name('people.show');

        Route::livewire('/departments', 'organization.departments')
            ->middleware(EnsureUserHasPermission::class.':departments.view')
            ->name('departments.index');

        Route::livewire('/job-functions', 'organization.job-functions')
            ->middleware(EnsureUserHasPermission::class.':job-functions.view')
            ->name('job-functions.index');

        Route::livewire('/audit-log', 'admin.audit-log')
            ->middleware(EnsureUserHasPermission::class.':audit-logs.view')
            ->name('audit-log');
    });

    Route::middleware([EnsureUserCanAccessControlCenter::class, EnsureUserIsAdmin::class])
        ->prefix('admin')
        ->group(function (): void {
            Route::livewire('/users', 'admin.users')->name('admin.users');
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
