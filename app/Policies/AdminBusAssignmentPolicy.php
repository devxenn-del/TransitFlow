<?php

namespace App\Policies;

use App\Models\AdminBusAssignment;
use App\Models\User;

/** Company-boundary-only — capability is gated by `permission:adminassignments.*` middleware. */
class AdminBusAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, AdminBusAssignment $assignment): bool
    {
        return $user->company_id !== null && $user->company_id === $assignment->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, AdminBusAssignment $assignment): bool
    {
        return $user->company_id !== null && $user->company_id === $assignment->company_id;
    }

    public function delete(User $user, AdminBusAssignment $assignment): bool
    {
        return $user->company_id !== null && $user->company_id === $assignment->company_id;
    }
}
