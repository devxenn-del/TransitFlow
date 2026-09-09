<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ForceEndTripRequest;
use App\Http\Resources\DispatchResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\TripMonitorResource;
use App\Models\Trip;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Company-side oversight of every trip: live board, remittance review /
 * approval, and force-ending a trip a conductor left hanging — BITS
 * tripmonitoring + remittances screens (docs/MIGRATION_MAP.md §4.2 / §4.3).
 *
 * Rows are constrained to the caller's company by CompanyScope (a
 * cross-company id 404s at route-model binding). Capability is gated by
 * `permission:tripmonitoring.*` / `remittances.*` on the routes.
 */
class TripMonitorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Trip::class);

        $trips = Trip::query()
            ->with(['conductor', 'driver', 'forceEndedBy', 'remittanceApprovedBy'])
            ->withCount('tickets')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('live'), fn ($q) => $q->live())
            ->when($request->filled('conductor_id'), fn ($q) => $q->where('conductor_id', $request->integer('conductor_id')))
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->boolean('flagged'), fn ($q) => $q->where('remittance_flagged', true))
            ->when($request->boolean('pending_approval'), fn ($q) => $q
                ->where('status', 'Arrived')
                ->whereNull('remittance_approved_at'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('started_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('started_at', '<=', $request->date('to')))
            ->orderByRaw("CASE WHEN status IN ('Departure','OnTrip') THEN 0 ELSE 1 END")
            ->orderByDesc('started_at')
            ->paginate($request->integer('per_page', 20));

        return TripMonitorResource::collection($trips);
    }

    public function show(Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        $trip->load(['conductor', 'driver', 'bus', 'forceEndedBy', 'remittanceApprovedBy'])
            ->loadCount('tickets');

        return response()->json([
            'data' => TripMonitorResource::make($trip),
            'dispatches' => DispatchResource::collection($trip->dispatches()->orderByDesc('id')->get()),
            'tickets' => TicketResource::collection(
                $trip->tickets()->with(['route', 'passengerType'])->orderByDesc('id')->get()
            ),
        ]);
    }

    public function forceEnd(ForceEndTripRequest $request, Trip $trip): TripMonitorResource
    {
        $this->authorize('view', $trip);

        if (! $trip->isLive()) {
            throw ValidationException::withMessages(['trip' => 'This trip has already ended.']);
        }

        $trip->update([
            'status' => 'Arrived',
            'ended_at' => now(),
            'force_ended_by' => $request->user()->id,
            'force_ended_at' => now(),
            'force_ended_reason' => $request->string('reason')->value(),
        ]);

        Audit::record('trip.force_ended', $trip, ['reason' => $request->string('reason')->value()]);

        return TripMonitorResource::make(
            $trip->fresh()->load(['conductor', 'driver', 'forceEndedBy', 'remittanceApprovedBy'])->loadCount('tickets')
        );
    }
}
