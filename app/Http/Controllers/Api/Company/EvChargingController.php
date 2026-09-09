<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\EndEvCharging;
use App\Actions\StartEvCharging;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\EndChargingRequest;
use App\Http\Requests\Company\StartChargingRequest;
use App\Http\Resources\EvChargingSessionResource;
use App\Models\EvChargingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * EV charging sessions for the current company — BITS
 * `ev_charging_sessions` (docs/MIGRATION_MAP.md §2.3). Auto-scoped by
 * CompanyScope; capability gated by `permission:charging.*`.
 */
class EvChargingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = EvChargingSession::query()
            ->with('bus')
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('active'), fn ($q) => $q->charging())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('started_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('started_at', '<=', $request->date('to')))
            ->orderByRaw("CASE WHEN status = 'Charging' THEN 0 ELSE 1 END")
            ->orderByDesc('started_at')
            ->paginate($request->integer('per_page', 20));

        return EvChargingSessionResource::collection($sessions);
    }

    public function store(StartChargingRequest $request, StartEvCharging $action): JsonResponse
    {
        $session = $action->handle($request->user(), $request->validated());

        return EvChargingSessionResource::make($session->load('bus'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(EvChargingSession $session): EvChargingSessionResource
    {
        return EvChargingSessionResource::make($session->load('bus'));
    }

    public function end(EndChargingRequest $request, EvChargingSession $session, EndEvCharging $action): EvChargingSessionResource
    {
        $session = $action->handle($request->user(), $session, $request->validated());

        return EvChargingSessionResource::make($session->load('bus'));
    }
}
