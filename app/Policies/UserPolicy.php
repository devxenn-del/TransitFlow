<?php

namespace App\Policies;

use App\Models\User;

/**
 * Company-boundary + self-protection for user management. The Super Admin
 * bypasses all of this via the Gate::before hook; route-level
 * `permission:accounts.*` middleware handles the capability check, so this
 * policy only has to keep a company admin inside their own company and stop
 * a few foot-guns.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, User $target): bool
    {
        return $this->sameCompany($user, $target);
    }

    public function create(User $user): bool
    {
        // The Super Admin bypasses this via Gate::before. A company admin can
        // only create accounts while the platform allows their company to.
        return $user->company_id !== null
            && ($user->company?->can_create_accounts ?? false);
    }

    public function update(User $user, User $target): bool
    {
        return $this->sameCompany($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        // No deleting yourself.
        return $user->id !== $target->id && $this->sameCompany($user, $target);
    }

    /**
     * Managing another user's individual permission grants.
     */
    public function managePermissions(User $user, User $target): bool
    {
        return $this->sameCompany($user, $target);
    }

    private function sameCompany(User $user, User $target): bool
    {
        return $user->company_id !== null && $user->company_id === $target->company_id;
    }
}
