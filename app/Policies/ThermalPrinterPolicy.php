<?php

namespace App\Policies;

use App\Models\ThermalPrinter;
use App\Models\User;

/**
 * Company-side authorization for thermal printers: a company may only
 * view the printers issued to it. There are deliberately no create /
 * update / delete abilities — the inventory is the Super Admin's
 * (`permission:printers.inventory.*`), and the Gate::before bypass covers
 * them. CompanyScope already makes another company's printer
 * un-fetchable; these checks are defence-in-depth.
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
}
