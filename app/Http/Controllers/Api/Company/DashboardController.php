<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Support\Reports\DashboardStats;
use Illuminate\Http\JsonResponse;

/**
 * The company dashboard — trips/collected today, the 7-day collection chart,
 * fleet counters and recent trips (BITS `admin/dashboard.php` /
 * `App\DashboardStats`, docs/MIGRATION_MAP.md §J). Company-scoped through the
 * models' global `CompanyScope`.
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->generate()]);
    }
}
