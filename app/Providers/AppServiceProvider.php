<?php

namespace App\Providers;

use App\Contracts\VideoProvider;
use App\Enums\Permission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Policies\CertificatePolicy;
use App\Policies\CoursePolicy;
use App\Policies\RolePolicy;
use App\Policies\TrainingRequirementPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserTrainingAssignmentPolicy;
use App\Services\SocialLogin\SocialLoginManager;
use App\Services\SocialLogin\WorkosAuthKitIdentityProvider;
use App\Services\Video\CloudflareStreamProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialLoginManager::class, fn ($app) => new SocialLoginManager([
            'workos' => $app->make(WorkosAuthKitIdentityProvider::class),
        ]));

        // The domain depends on the contract, never on Cloudflare. Swapping providers is a
        // binding change here plus a new implementation — nothing else moves.
        $this->app->bind(VideoProvider::class, CloudflareStreamProvider::class);
    }

    public function boot(): void
    {
        // Administrators bypass permission Gates here — never inside domain Actions or
        // Policies, so the bypass stays auditable in one place.
        Gate::before(fn (User $user): ?bool => $user->isAdmin() ? true : null);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(TrainingRequirement::class, TrainingRequirementPolicy::class);
        Gate::policy(UserTrainingAssignment::class, UserTrainingAssignmentPolicy::class);
        Gate::policy(Certificate::class, CertificatePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        View::addNamespace('layouts', resource_path('views/components/layouts'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('compliance-events', fn (Request $request) => Limit::perMinute(120)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
