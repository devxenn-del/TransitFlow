<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One CompanyContext per request/job — populated by
        // App\Http\Middleware\ResolveCompanyContext, read by CompanyScope.
        $this->app->singleton(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            // The Super Admin operates the platform and is above every check.
            if ($user->isSuperAdmin()) {
                return true;
            }

            // A dotted ability that isn't a registered Gate/policy method is
            // treated as a BITS permission key ("companies.view",
            // "users.create", …) and checked against `user_permissions` —
            // this is the Laravel equivalent of BITS' hasPermission().
            if (str_contains($ability, '.') && ! Gate::has($ability)) {
                return $user->hasPermissionTo($ability);
            }

            // Anything else (policy abilities) falls through to the policy.
            return null;
        });
    }
}
