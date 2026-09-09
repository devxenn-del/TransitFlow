<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

/**
 * Company-boundary backstop for drivers. `permission:drivers.*` on the
 * routes gates capability; Super Admin passes via Gate::before; CompanyScope
 * hides other companies' rows.
 */
class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->company_id !== null && $user->company_id === $driver->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->company_id !== null && $user->company_id === $driver->company_id;
    }

    public function delete(User $user, Driver $driver): bool
    {
        return $user->company_id !== null && $user->company_id === $driver->company_id;
    }
}
