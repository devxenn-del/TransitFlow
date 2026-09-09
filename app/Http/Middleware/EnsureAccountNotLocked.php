<?php

namespace App\Http\Middleware;

use App\Support\AccountLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account lock re-checked on every authenticated request — BITS
 * `auth/authorize.php` / `auth/apiAuth.php`'s is_account_locked() call
 * (docs/MIGRATION_MAP.md §5). Sign-out stays reachable so a locked user can
 * still get out of the session.
 */
class EnsureAccountNotLocked
{
    private const ALLOWED = ['auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && AccountLock::isLocked($user) && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json([
                'message' => AccountLock::reasonMessage($user),
                'lock_type' => $user->lock_type,
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
