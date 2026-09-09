<?php

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

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|storage|build|up).*$')
    ->name('spa');
