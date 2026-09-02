<?php

namespace App\Providers;

use App\Contracts\VideoProvider;
use App\Enums\Permission;
use App\Http\Middleware\AuthenticatePlatformTaskUser;
use App\Http\Middleware\EnsureUserIsPlatformAdmin;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Module;
use App\Models\PlatformTaskUser;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Policies\CertificatePolicy;
use App\Policies\CoursePolicy;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\TrainingRequirementPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserTrainingAssignmentPolicy;
use App\Services\Settings\ApplicationSettings;
use App\Services\SocialLogin\SocialLoginManager;
use App\Services\SocialLogin\WorkosAuthKitIdentityProvider;
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

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ApplicationSettings::class);

        $this->app->singleton(SocialLoginManager::class, fn ($app) => new SocialLoginManager([
            'workos' => $app->make(WorkosAuthKitIdentityProvider::class),
        ]));

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
        // The package names this action `upload`, which collides with Livewire's
        // client-side file-upload helper. Rename it while compiling the package's
        // single-file component until the upstream component can adopt the name.
        Livewire::prepareViewsForCompilationUsing(function (string $contents, string $path): string {
            $taskEditorPath = base_path('vendor/andremellow/laravel-tasks/resources/views/tasks/show.blade.php');

            if (realpath($path) !== realpath($taskEditorPath)) {
                return $contents;
            }

            return str_replace('public function upload(', 'public function attachTaskMedia(', $contents);
        });

        // Livewire actions are posted to its own update endpoint. Reapply the
        // platform route's authentication there so task modals keep the same actor.
        Livewire::addPersistentMiddleware([
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

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        Gate::define('tasks.access', fn (User|PlatformTaskUser $user): bool => $user->account?->is_platform_admin === true
            && $user->account->status === 'active');

        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Module::class, ModulePolicy::class);
        Gate::policy(TrainingRequirement::class, TrainingRequirementPolicy::class);
        Gate::policy(UserTrainingAssignment::class, UserTrainingAssignmentPolicy::class);
        Gate::policy(Certificate::class, CertificatePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

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
