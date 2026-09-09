<?php

namespace App\Policies;

use App\Models\ThermalPrinter;
use App\Models\User;

/**
 * Company-boundary-only authorization for thermal printers — capability is
 * gated separately by `permission:thermalprinters.*` middleware on the
 * routes. Mirrors BusPolicy/TerminalPolicy. CompanyScope already makes a
 * printer from another company un-fetchable; these checks are
 * defence-in-depth.
 */
class ThermalPrinterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, ThermalPrinter $printer): bool
    {
        return $user->company_id !== null && $user->company_id === $printer->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, ThermalPrinter $printer): bool
    {
        return $user->company_id !== null && $user->company_id === $printer->company_id;
    }

    public function delete(User $user, ThermalPrinter $printer): bool
    {
        return $user->company_id !== null && $user->company_id === $printer->company_id;
    }
}
