<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a request when the bound company (App\Support\CompanyContext) is
 * not Active — Inactive or Suspended. Platform accounts (Super Admin) are
 * never blocked, including a Super Admin who has scoped into a suspended
 * company to inspect it.
 *
 * Runs right after ResolveCompanyContext, so it reads the context rather
 * than the request user.
 */
class EnsureCompanyIsActive
{
    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->isSuperAdmin() && $this->context->hasCompany()) {
            $company = Company::query()->find($this->context->companyId());

            if ($company === null || ! $company->isActive()) {
                abort(Response::HTTP_FORBIDDEN, 'Your company account is not active. Contact the TransitFlow administrator.');
            }
        }

        return $next($request);
    }
}
