<?php

namespace App\Policies;

use App\Models\Terminal;
use App\Models\User;

/**
 * Company-boundary backstop for terminals. Capability is gated by
 * `permission:terminals.*` on the routes; the Super Admin passes via
 * Gate::before; CompanyScope already hides other companies' rows.
 */
class TerminalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, Terminal $terminal): bool
    {
        return $user->company_id !== null && $user->company_id === $terminal->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, Terminal $terminal): bool
    {
        return $user->company_id !== null && $user->company_id === $terminal->company_id;
    }

    public function delete(User $user, Terminal $terminal): bool
    {
        return $user->company_id !== null && $user->company_id === $terminal->company_id;
    }
}
