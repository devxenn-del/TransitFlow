<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Super Admin is allowed everything here via the Gate::before hook in
 * AppServiceProvider, so these methods only describe what a *company* user
 * may do: see and edit their own company, nothing else.
 */
class CompanyPolicy
{
    /**
     * Listing every company on the platform is Super-Admin-only.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * A Company Admin may edit their own company's profile.
     */
    public function update(User $user, Company $company): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($company);
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    /**
     * Changing lifecycle status (activate / deactivate / suspend) is
     * Super-Admin-only.
     */
    public function updateStatus(User $user, Company $company): bool
    {
        return false;
    }
}
