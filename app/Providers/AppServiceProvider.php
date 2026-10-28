<?php

namespace App\Providers;

use App\Contracts\VideoProvider;
use App\Enums\Permission;
use App\Http\Middleware\AuthenticatePlatformTaskUser;
use App\Http\Middleware\EnsureComplianceAccess;
use App\Http\Middleware\EnsurePersonProductAccess;
use App\Http\Middleware\EnsureUserIsPlatformAdmin;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\LessonDocument;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\PlatformTaskUser;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\WorkosSyncRun;
use App\Policies\CertificatePolicy;
use App\Policies\CoursePolicy;
use App\Policies\LessonDocumentPolicy;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\TrainingRequirementPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserTrainingAssignmentPolicy;
use App\Policies\WorkosSyncRunPolicy;
use App\Services\Platform\ComplianceAuthenticationContext;
use App\Services\Platform\ComplianceCompanyContextHook;
use App\Services\Platform\ComplianceCompanyInitializer;
use App\Services\Platform\CompliancePeopleDirectoryExtension;
use App\Services\Settings\ApplicationSettings;
use App\Services\Video\CloudflareStreamProvider;
use App\Services\Video\FakeVideoProvider;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Oceanix\Platform\Access\GateRegistrar;
use Oceanix\Platform\Auth\Contracts\AuthenticationContext;
use Oceanix\Platform\Contracts\CompanyContextHook;
use Oceanix\Platform\Contracts\CompanyInitializer;
use Oceanix\Platform\Contracts\PeopleDirectoryExtension;
use Oceanix\Platform\Livewire\PlatformCompanies;
use Oceanix\Platform\Livewire\PlatformUsers;
use Tests\Support\AccountIntegrationFixture;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(\Oceanix\Platform\Tenancy\TenantContext::class, fn ($app) => $app->make(TenantContext::class));
        $this->app->scoped(ApplicationSettings::class);
        $this->app->scoped(CompanyInitializer::class, ComplianceCompanyInitializer::class);
        $this->app->scoped(PeopleDirectoryExtension::class, CompliancePeopleDirectoryExtension::class);
        $this->app->scoped(CompanyContextHook::class, ComplianceCompanyContextHook::class);

        $this->app->scoped(AuthenticationContext::class, ComplianceAuthenticationContext::class);
        foreach (config('oceanix-platform.legacy_bindings', []) as $shared => $legacy) {
            $this->app->bind($shared, fn ($app) => $app->make($legacy));
        }

        // The domain depends on the contract, never on Cloudflare. Swapping providers is a
        // binding change here plus a new implementation — nothing else moves.
        //
        // Local development falls back to a file-backed stand-in so the editor, the
        // publication rules and the player can be exercised before Cloudflare credentials
        // exist. Any other environment always gets the real provider, configured or not:
        // failing loudly beats silently serving fake video.
        $this->app->bind(VideoProvider::class, function ($app) {
            $useFake = $app->environment('local') && ! CloudflareStreamProvider::isConfigured();

            return $app->make($useFake ? FakeVideoProvider::class : CloudflareStreamProvider::class);
        });
    }

    public function boot(): void
    {
        // Retain historical Livewire aliases while using package-owned screen behavior.
        Livewire::component('platform.users', PlatformUsers::class);
        Livewire::component('platform.companies', PlatformCompanies::class);

        if (getenv('COMPANY_USER_QA_FIXTURE') === '1') {
            AccountIntegrationFixture::bootHttpQa();
        }
        // Livewire actions are posted to its own update endpoint. Reapply the
        // platform route's authentication there so task modals keep the same actor.
        Livewire::addPersistentMiddleware([
            EnsureComplianceAccess::class,
            EnsurePersonProductAccess::class,
            EnsureUserIsPlatformAdmin::class,
            AuthenticatePlatformTaskUser::class,
        ]);

        // Administrators bypass permission Gates here — never inside domain Actions or
        // Policies, so the bypass stays auditable in one place.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            // This model is only installed by the platform Tasks middleware after the
            // platform administrator session has been validated. Tasks are a global
            // platform workspace, so that administrator may perform every task action.
            if ($user instanceof PlatformTaskUser) {
                return true;
            }

            $record = $arguments[0] ?? null;
            $isSharedContentWrite = in_array($ability, ['update', 'updateVersion', 'publish', 'retire'], true)
                && ($record instanceof Course || $record instanceof Module)
                && (bool) $record->getAttribute('is_shared');

            return $user->isAdmin() && ! $isSharedContentWrite ? true : null;
        });

        app(GateRegistrar::class)->register();

        Gate::define('tasks.access', fn (User|PlatformTaskUser $user): bool => $user->account?->is_platform_admin === true
            && $user->account->status === 'active');

        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(LessonDocument::class, LessonDocumentPolicy::class);
        Gate::policy(CourseVersion::class, CoursePolicy::class);
        Gate::policy(Module::class, ModulePolicy::class);
        Gate::policy(ModuleVersion::class, ModulePolicy::class);
        Gate::policy(TrainingRequirement::class, TrainingRequirementPolicy::class);
        Gate::policy(UserTrainingAssignment::class, UserTrainingAssignmentPolicy::class);
        Gate::policy(Certificate::class, CertificatePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(WorkosSyncRun::class, WorkosSyncRunPolicy::class);

        View::addNamespace('layouts', resource_path('views/components/layouts'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('tasks-api', fn (Request $request) => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('compliance-events', fn (Request $request) => Limit::perMinute(120)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
