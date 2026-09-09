<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Redeems the one-time token minted by
 * App\Http\Controllers\Api\Mobile\WebSessionController — establishes a
 * normal `web`-guard session for the mobile app's WebView, then redirects
 * into the SPA. An invalid, expired, or already-used token just lands the
 * visitor on the SPA's ordinary signed-out state.
 */
class MobileSessionController extends Controller
{
    public function redeem(Request $request, string $token): RedirectResponse
    {
        $payload = Cache::pull("mobile-webview-session:{$token}");

        if ($payload === null) {
            return redirect('/');
        }

        $user = User::query()->find($payload['user_id']);

        if ($user === null || ! $user->isActive()) {
            return redirect('/');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect($payload['redirect'] ?? '/dashboard');
    }
}
