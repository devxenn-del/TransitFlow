<?php

namespace App\Support;

use Closure;

/**
 * Holds the "current company" for the lifetime of a request (or a job, or
 * an artisan command).
 *
 * Every company-owned model reads this through App\Models\Scopes\CompanyScope
 * to constrain its queries. It is populated once, early, by
 * App\Http\Middleware\ResolveCompanyContext from the authenticated user —
 * never from client input on a normal request.
 *
 * Registered as a singleton in the container, so `app(CompanyContext::class)`
 * and the `CompanyContext` facade-free helpers all see the same instance.
 */
class CompanyContext
{
    private ?int $companyId = null;

    private bool $isSuperAdmin = false;

    /**
     * When true, CompanyScope does not constrain queries at all. Used for
     * genuinely cross-company work (platform reports, the Super Admin
     * listing every company's buses) via {@see actAcrossCompanies()}.
     */
    private bool $spanningAllCompanies = false;

    private bool $resolved = false;

    /**
     * Bind the context to a single company. Passing null with
     * $isSuperAdmin = true means "platform account, no company".
     */
    public function set(?int $companyId, bool $isSuperAdmin = false): void
    {
        $this->companyId = $companyId;
        $this->isSuperAdmin = $isSuperAdmin;
        $this->resolved = true;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function hasCompany(): bool
    {
        return $this->companyId !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Whether CompanyScope should apply right now. It should NOT when we
     * have no company bound (unauthenticated, or a Super Admin who has not
     * scoped into a specific company) or when {@see actAcrossCompanies()}
     * is active.
     */
    public function shouldScopeQueries(): bool
    {
        return ! $this->spanningAllCompanies && $this->companyId !== null;
    }

    /**
     * Run $callback with company scoping switched off, then restore the
     * previous state (even if $callback throws).
     */
    public function actAcrossCompanies(Closure $callback): mixed
    {
        $previous = $this->spanningAllCompanies;
        $this->spanningAllCompanies = true;

        try {
            return $callback();
        } finally {
            $this->spanningAllCompanies = $previous;
        }
    }

    /**
     * Run $callback as though the given company were the current one, then
     * restore. Lets a Super Admin operate inside one company's data
     * deliberately and briefly.
     */
    public function actAsCompany(?int $companyId, Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousSpanning = $this->spanningAllCompanies;

        $this->companyId = $companyId;
        $this->spanningAllCompanies = false;

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->spanningAllCompanies = $previousSpanning;
        }
    }

    /**
     * Forget everything. Mainly for test isolation between requests.
     */
    public function reset(): void
    {
        $this->companyId = null;
        $this->isSuperAdmin = false;
        $this->spanningAllCompanies = false;
        $this->resolved = false;
    }
}
