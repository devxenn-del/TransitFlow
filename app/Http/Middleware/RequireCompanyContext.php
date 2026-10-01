<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the `company/*` API: every one of those endpoints reads or writes
 * one company's records, so a company must be bound. A company user always
 * has their own; a Super Admin must name one (`X-Company-Id`), otherwise
 * they would be listing every company's rows mixed together — or creating
 * a record that belongs to no company.
 */
class RequireCompanyContext
{
    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $this->context->hasCompany()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Select a company first.');
        }

        return $next($request);
    }
}
