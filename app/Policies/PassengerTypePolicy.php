<?php

namespace App\Policies;

use App\Models\PassengerType;
use App\Models\User;

/**
 * Company-boundary backstop for passenger types (and, transitively, their
 * articles). `permission:passengertypes.*` on the routes gates capability.
 */
class PassengerTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, PassengerType $type): bool
    {
        return $user->company_id !== null && $user->company_id === $type->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, PassengerType $type): bool
    {
        return $user->company_id !== null && $user->company_id === $type->company_id;
    }

    public function delete(User $user, PassengerType $type): bool
    {
        return $user->company_id !== null && $user->company_id === $type->company_id;
    }
}
