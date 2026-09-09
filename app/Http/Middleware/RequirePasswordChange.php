<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a user is flagged `must_change_password`, every authenticated API
 * call is refused (423 Locked) except the handful needed to sign out or
 * set the new password. Backs up the SPA's forced-change screen so the
 * rule can't be skipped by a direct API call.
 */
class RequirePasswordChange
{
    /** Route names that stay reachable while the flag is set. */
    private const ALLOWED = ['auth.me', 'auth.me.alias', 'auth.logout', 'auth.password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'You must set a new password before continuing.',
                'must_change_password' => true,
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
