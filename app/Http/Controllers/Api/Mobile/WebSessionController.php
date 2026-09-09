<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time hand-off from the mobile app's bearer-token session to the SPA's
 * cookie session, so the app's in-app WebView can show the same
 * authenticated React admin pages the browser sees — the unified
 * TransitFlow app's route into every Company Admin / Manager / Chairman /
 * Super Admin screen without duplicating that UI natively.
 *
 * The token is random, single-use (Cache::pull on redemption — see
 * routes/web.php `mobile/session/{token}`) and expires in 60 seconds. The
 * API bearer token itself is never exposed to the WebView's JS context;
 * this only ever hands over a fresh `web`-guard session cookie.
 */
class WebSessionController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $token = Str::random(48);

        Cache::put("mobile-webview-session:{$token}", [
            'user_id' => $request->user()->id,
            'redirect' => $this->safeRedirect($request->string('redirect')->value()),
        ], now()->addSeconds(60));

        return response()->json([
            'url' => rtrim((string) config('app.url'), '/')."/mobile/session/{$token}",
        ]);
    }

    /** Only an internal relative path is ever honoured — anything else falls back to /dashboard. */
    private function safeRedirect(?string $path): string
    {
        if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '://')) {
            return '/dashboard';
        }

        return $path;
    }
}
