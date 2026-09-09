<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\User;

/**
 * Company-scoped authorization for buses. This is a Phase 3 placeholder:
 * it enforces the company boundary only. Phase 4 layers the BITS-style
 * per-permission-key checks (`buses.view`, `buses.create`, …) on top.
 *
 * Super Admin passes everything via the Gate::before hook. The
 * `$user->company_id === $bus->company_id` checks below are a
 * defence-in-depth backstop — CompanyScope already makes a bus from
 * another company un-fetchable for a company user in the first place.
 */
class BusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, Bus $bus): bool
    {
        return $user->company_id !== null && $user->company_id === $bus->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, Bus $bus): bool
    {
        return $user->company_id !== null && $user->company_id === $bus->company_id;
    }

    public function delete(User $user, Bus $bus): bool
    {
        return $user->company_id !== null && $user->company_id === $bus->company_id;
    }
}
