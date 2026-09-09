<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for a BITS permission key:
 *
 *   Route::get(...)->middleware('permission:companies.view');
 *   Route::get(...)->middleware('permission:users.create,users.edit'); // any of
 *
 * Delegates to the Gate, so the Super Admin bypass and the
 * `user_permissions` lookup (AppServiceProvider) apply uniformly. 401 if
 * unauthenticated, 403 if the key is not granted.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($request->user() === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        foreach ($permissions as $permission) {
            if (Gate::allows($permission)) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have permission to do that.');
    }
}
