<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Support\LiveBoard;
use Illuminate\Http\JsonResponse;

/**
 * The office live-monitoring board — fleet status, buses just arrived, and
 * the recent-trips card (BITS `office/dashboard.php` + fleet map,
 * docs/MIGRATION_MAP.md §H). The SPA polls this every ~3 s; the payload is
 * company-scoped through the models' global `CompanyScope`.
 */
class LiveMonitorController extends Controller
{
    public function __invoke(LiveBoard $board): JsonResponse
    {
        return response()->json(['data' => $board->generate()]);
    }
}
