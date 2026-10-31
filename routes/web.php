<?php

use App\Actions\Auth\StartUserImpersonation;
use App\Actions\Auth\StopUserImpersonation;
use App\Enums\UserStatus;
use App\Http\Controllers\CertificateDownloadController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\ComplianceExportController;
use App\Http\Controllers\CoursePreviewController;
use App\Http\Controllers\DevVideoController;
use App\Http\Controllers\LessonDocumentController;
use App\Http\Controllers\LessonDocumentLibraryController;
use App\Http\Controllers\Platform\EditorFileUploadController;
use App\Http\Controllers\PlatformCoursePreviewController;
use App\Http\Controllers\TrainingPlaybackController;
use App\Http\Middleware\EnsurePersonProductAccess;
use App\Http\Middleware\EnsurePlatformHasPermission;
use App\Http\Middleware\EnsureUserCanAccessControlCenter;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\PublicCoursePreview;
use App\Http\Middleware\SetLocale;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Oceanix\Platform\Http\Middleware\EnsurePlatformAdministrator;
use Oceanix\Platform\Http\Middleware\IdentifyCompany;
use Oceanix\Platform\Models\Account;
use Oceanix\Platform\Models\Company;
use Oceanix\Platform\Services\Platform\PlatformAccess;

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

});

Route::prefix('platform')
    ->middleware(EnsurePlatformAdministrator::class)
    ->group(function (): void {
        Route::get('/lesson-document-library/{document}', [LessonDocumentLibraryController::class, 'platform'])
            ->whereUuid('document')->middleware(EnsurePlatformHasPermission::class.':lesson-documents.view')->name('platform.lesson-documents.library.open');
        Route::post('/editor-uploads/{platformAccount}', [EditorFileUploadController::class, 'handle'])
            ->whereNumber('platformAccount')->name('platform.editor-uploads.store');
        Route::livewire('/shared-courses', 'platform.shared-courses.index')->name('platform.shared-courses.index');
        Route::livewire('/shared-courses/{course}', 'platform.shared-courses.show')->name('platform.shared-courses.show');
        Route::livewire('/shared-courses/{course}/editor', 'platform.shared-courses.editor')->name('platform.shared-courses.editor');
        Route::prefix('/shared-courses/{course}/versions/{version}/preview')->middleware(EnsurePlatformHasPermission::class.':shared-courses.view')->group(function (): void {
            Route::get('/items/{kind}/{item}/documents/{document}', [LessonDocumentController::class, 'platformCourse'])->whereUuid('document')->name('platform.shared-courses.documents');
            Route::get('/', [PlatformCoursePreviewController::class, 'show'])->name('platform.shared-courses.preview');
            Route::post('/items/{kind}/{item}/playback', [PlatformCoursePreviewController::class, 'playback'])->middleware('throttle:playback')->name('platform.shared-courses.preview-playback');
            Route::get('/items/{kind}/{item}/media/{asset}', [PlatformCoursePreviewController::class, 'media'])->name('platform.shared-courses.preview-media');
        });
        Route::livewire('/shared-modules', 'platform.shared-modules.index')->middleware(EnsurePlatformHasPermission::class.':shared-modules.view')->name('platform.shared-modules.index');
        Route::livewire('/shared-modules/{module}', 'platform.shared-modules.show')->middleware(EnsurePlatformHasPermission::class.':shared-modules.view')->name('platform.shared-modules.show');
        Route::livewire('/shared-modules/{module}/editor', 'platform.shared-modules.editor')->middleware(EnsurePlatformHasPermission::class.':shared-modules.update')->name('platform.shared-modules.editor');
        Route::livewire('/shared-modules/{module}/preview', 'platform.shared-modules.preview')->middleware(EnsurePlatformHasPermission::class.':shared-modules.view')->name('platform.shared-modules.preview');
        Route::get('/shared-modules/{module}/preview/documents/{document}', [LessonDocumentController::class, 'platformModule'])->middleware(EnsurePlatformHasPermission::class.':shared-modules.view')->whereUuid('document')->name('platform.shared-modules.documents');
        Route::livewire('/companies/{company}/courses/{course}', 'platform.shared-courses.show')->name('platform.companies.courses.show');
    });

// Keep existing bookmarks and notification links useful while tenant URLs migrate to
// /c/{slug}. These endpoints only redirect after the active company has been identified.
Route::middleware([IdentifyCompany::class, 'auth', EnsurePersonProductAccess::class])->group(function (): void {
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

Route::get('/c/{company:slug}', function (Company $company) {
    return Auth::check()
        ? redirect()->route('dashboard', ['company' => $company])
        : redirect()->route('tenant.login', ['company' => $company]);
})->middleware(IdentifyCompany::class)->name('company.entry');

Route::prefix('c/{company:slug}')
    ->middleware([IdentifyCompany::class, 'auth', EnsurePersonProductAccess::class])
    ->group(function (): void {
        Route::get('/lesson-document-library/{document}', [LessonDocumentLibraryController::class, 'company'])
            ->whereUuid('document')->middleware(EnsureUserHasPermission::class.':lesson-documents.view')->name('lesson-documents.library.open');
        // Every authenticated person lands here. The component renders the compliance overview
        // for operators and the personal training board for everyone else.
        Route::livewire('/dashboard', 'dashboard')->name('dashboard');
        Route::livewire('/my-training', 'training.my-training')->name('my-training');
        Route::livewire('/my-training/{assignment}', 'training.assignment')->name('my-training.show');
        Route::post('/people/{user}/impersonate', function (Company $company, User $user, StartUserImpersonation $action) {
            $action->handle(auth()->user(), $user);

            return redirect()->route('dashboard', ['company' => $company]);
        })->name('impersonation.start');
        Route::post('/impersonation/stop', function (Company $company, StopUserImpersonation $action) {
            $action->handle();

            return redirect()->route('dashboard', ['company' => $company]);
        })->name('impersonation.stop');
        Route::get('/certificates/{certificate}/download', CertificateDownloadController::class)
            ->name('certificates.download');
        Route::livewire('/my-training/{assignment}/lessons/{lesson}', 'training.lesson')->name('my-training.lesson');
        Route::get('/my-training/{assignment}/lessons/{lesson}/documents/{document}', [LessonDocumentController::class, 'training'])->whereUuid('document')->name('my-training.documents');

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
            Route::get('/courses/{course}/lessons/{lesson}/preview/documents/{document}', [LessonDocumentController::class, 'company'])->middleware(EnsureUserHasPermission::class.':courses.update')->whereUuid('document')->name('courses.lessons.documents');
            Route::livewire('/courses', 'courses.index')
                ->middleware(EnsureUserHasPermission::class.':courses.view')
                ->name('courses.index');
            Route::livewire('/courses/{course}', 'courses.show')
                ->middleware(EnsureUserHasPermission::class.':courses.view')
                ->name('courses.show');
            Route::livewire('/courses/{course}/editor', 'courses.editor')
                ->middleware(EnsureUserHasPermission::class.':courses.update')
                ->name('courses.editor');
            Route::livewire('/courses/{course}/lessons/{lesson}/preview', 'courses.lesson-preview')
                ->middleware(EnsureUserHasPermission::class.':courses.update')
                ->name('courses.lessons.preview');
            Route::livewire('/shared-courses', 'courses.shared-index')
                ->middleware(EnsureUserHasPermission::class.':shared-courses.view')
                ->name('shared-courses.index');
            Route::livewire('/shared-courses/{course}', 'courses.show')
                ->middleware(EnsureUserHasPermission::class.':shared-courses.view')
                ->name('shared-courses.show');

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

            Route::livewire('/people/import', 'organization.import-people')
                ->middleware(EnsureUserHasPermission::class.':people.import')
                ->name('people.import');

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

    });

// Capability routes intentionally do not resolve or change the visitor's company.
Route::prefix('preview/courses/{token}')->withoutMiddleware([IdentifyCompany::class, SetLocale::class])
    ->middleware(PublicCoursePreview::class)->group(function (): void {
        Route::get('/items/{kind}/{item}/documents/{document}', [LessonDocumentController::class, 'preview'])->whereUuid('document')->name('course-preview.documents');
        Route::get('/', [CoursePreviewController::class, 'show'])->name('course-preview.show');
        Route::get('/items/{kind}/{item}', [CoursePreviewController::class, 'item'])->name('course-preview.item');
        Route::post('/items/{kind}/{item}/playback', [CoursePreviewController::class, 'playback'])->name('course-preview.playback');
        Route::get('/items/{kind}/{item}/media/{asset}', [CoursePreviewController::class, 'media'])->name('course-preview.local-media');
        Route::post('/locale/{locale}', [CoursePreviewController::class, 'locale'])->name('course-preview.locale');
    });
Route::match(['get', 'post'], '/c/{company:slug}/courses/{course}/versions/{version}/preview-link', [CoursePreviewController::class, 'operator'])
    ->middleware(['auth', EnsureUserHasPermission::class.':courses.preview-links.generate', 'throttle:10,1'])
    ->name('courses.preview-link');
Route::match(['get', 'post'], '/platform/shared-courses/{course}/versions/{version}/preview-link', [CoursePreviewController::class, 'operator'])
    ->middleware([EnsurePlatformAdministrator::class, EnsurePlatformHasPermission::class.':shared-courses.preview-links.generate', 'throttle:10,1'])
    ->name('platform.shared-courses.preview-link');
