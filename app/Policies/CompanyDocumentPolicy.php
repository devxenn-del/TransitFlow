<?php

namespace App\Policies;

use App\Models\CompanyDocument;
use App\Models\User;

/**
 * Company-boundary backstop for company documents. `permission:documents.*`
 * on the routes gates capability; Super Admin passes via Gate::before;
 * CompanyScope hides other companies' rows.
 */
class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return $user->belongsToCompany($document->company_id);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, CompanyDocument $document): bool
    {
        return $user->belongsToCompany($document->company_id);
    }

    public function delete(User $user, CompanyDocument $document): bool
    {
        return $user->belongsToCompany($document->company_id);
    }
}
