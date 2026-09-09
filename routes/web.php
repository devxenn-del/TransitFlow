<?php

use App\Http\Controllers\Web\MobileSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| TransitFlow ships a single-page React application. Every non-API, non-asset
| request returns the SPA shell and React Router takes over client-side.
|
*/

// One-time hand-off from the mobile app (see Api\Mobile\WebSessionController)
// into an authenticated SPA session for the app's in-app WebView. Must be
// registered — and excluded from the catch-all's regex below — before the
// SPA route, since Laravel matches the first registered route.
Route::get('mobile/session/{token}', [MobileSessionController::class, 'redeem'])
    ->middleware('throttle:30,1')
    ->name('mobile.session');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|storage|build|up|mobile).*$')
    ->name('spa');
