<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the current company into App\Support\CompanyContext for the rest of
 * the request, from the authenticated user — the single point where "which
 * company am I?" is decided.
 *
 * - A company user (or company admin): their own `company_id`, always.
 *   Client input is never consulted, so tampering with a URL id, request
 *   body, or header cannot widen their view.
 * - A Super Admin: no company by default (they see the platform). They may
 *   opt into one company's data for the request with the `X-Company-Id`
 *   header or a `?company=` query parameter — and only a Super Admin can.
 *
 * Runs before SubstituteBindings (see the priority list in bootstrap/app.php)
 * so route-model binding is already company-scoped: another company's record
 * 404s at the router, before any controller or policy sees it.
 */
class ResolveCompanyContext
{
    /**
     * Routes that establish a NEW identity rather than use an existing one.
     * A browser/app that was previously signed in as someone else (or still
     * has an old, unrelated token attached — the web/mobile HTTP clients
     * send whatever token is stored on every request, this one included)
     * must never have that stale identity's company silently scope the
     * credential/driver-code lookups inside AuthController::login()/
     * pinLogin() — otherwise a completely correct email+password+driver
     * code for a DIFFERENT company than the stale token's gets rejected as
     * if it were wrong (or, via EnsureCompanyIsActive, blocked outright if
     * that unrelated company happens to be suspended).
     */
    private const IDENTITY_ESTABLISHING_ROUTES = ['auth.login', 'auth.pin-login'];

    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::IDENTITY_ESTABLISHING_ROUTES, true)) {
            return $next($request);
        }

        // Resolve via the Sanctum guard explicitly: this middleware runs
        // before the route's `auth:sanctum`, so the default guard has not
        // been pointed at Sanctum yet. A missing/invalid token yields null
        // here and `auth:sanctum` still returns the 401 later.
        $user = $request->user('sanctum') ?? $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            $this->context->set($this->superAdminScopedCompanyId($request), isSuperAdmin: true);
        } else {
            $this->context->set($user->company_id, isSuperAdmin: false);
        }

        return $next($request);
    }

    /**
     * A Super Admin's optionally-chosen company for this request. Returns
     * null (platform-wide view) unless they explicitly named a real company.
     */
    private function superAdminScopedCompanyId(Request $request): ?int
    {
        $requested = $request->header('X-Company-Id') ?? $request->query('company');

        if ($requested === null || ! ctype_digit((string) $requested)) {
            return null;
        }

        return Company::query()->whereKey((int) $requested)->value('id');
    }
}
