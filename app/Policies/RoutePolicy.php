<?php

namespace App\Policies;

use App\Models\Route;
use App\Models\User;

/**
 * Company-boundary backstop for routes. See TerminalPolicy for the model.
 */
class RoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function view(User $user, Route $route): bool
    {
        return $user->company_id !== null && $user->company_id === $route->company_id;
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null;
    }

    public function update(User $user, Route $route): bool
    {
        return $user->company_id !== null && $user->company_id === $route->company_id;
    }

    public function delete(User $user, Route $route): bool
    {
        return $user->company_id !== null && $user->company_id === $route->company_id;
    }
}
