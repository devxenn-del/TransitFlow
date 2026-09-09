<?php

namespace App\Policies;

use App\Models\Franchise;
use App\Models\User;

/**
 * Company-boundary backstop for franchises. Capability is gated by
 * `permission:franchises.*` on the routes; Super Admin passes via
 * Gate::before; CompanyScope hides other companies' rows.
 */
class FranchisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, Franchise $franchise): bool
    {
        return $user->company_id !== null && $user->company_id === $franchise->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, Franchise $franchise): bool
    {
        return $user->company_id !== null && $user->company_id === $franchise->company_id;
    }

    public function delete(User $user, Franchise $franchise): bool
    {
        return $user->company_id !== null && $user->company_id === $franchise->company_id;
    }
}
