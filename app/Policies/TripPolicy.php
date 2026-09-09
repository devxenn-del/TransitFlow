<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;

/**
 * A conductor may only act on their own trips. Company staff with
 * `tripmonitoring.view` may view any trip in the company (used by the admin
 * monitoring screens later). Super Admin passes via Gate::before.
 */
class TripPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('tripmonitoring.view') || $user->hasPermissionTo('trips.view');
    }

    public function view(User $user, Trip $trip): bool
    {
        if ($user->company_id !== $trip->company_id) {
            return false;
        }

        return $user->id === $trip->conductor_id || $user->hasPermissionTo('tripmonitoring.view');
    }

    public function update(User $user, Trip $trip): bool
    {
        return $user->company_id === $trip->company_id && $user->id === $trip->conductor_id;
    }
}
