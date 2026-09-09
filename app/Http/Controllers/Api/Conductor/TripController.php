<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Actions\StartTrip;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\CancelTripRequest;
use App\Http\Requests\Conductor\EndTripRequest;
use App\Http\Requests\Conductor\StartTripRequest;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Support\TripRemittance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The conductor's own trip lifecycle (BITS' conductor/ web pages + api/trips/*).
 * Every action operates on the authenticated conductor's trip — the id is
 * never taken from the request.
 */
class TripController extends Controller
{
    /** GET /api/conductor/trips/active — the current live trip, or null. */
    public function active(Request $request): TripResource|JsonResponse
    {
        $trip = $this->liveTripFor($request);

        return $trip
            ? TripResource::make($trip->loadCount('tickets')->load('driver'))
            : response()->json(['data' => null]);
    }

    public function start(StartTripRequest $request, StartTrip $action): JsonResponse
    {
        $trip = $action->handle($request->user(), $request->validated());

        return TripResource::make($trip->loadCount('tickets')->load('driver'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function markOnTrip(Request $request): TripResource
    {
        $trip = $this->liveTripFor($request);

        if ($trip === null || $trip->status !== 'Departure') {
            throw ValidationException::withMessages(['trip' => 'No trip is waiting at the terminal.']);
        }

        $trip->update(['status' => 'OnTrip', 'marked_on_trip_at' => now()]);

        return TripResource::make($trip->fresh()->loadCount('tickets')->load('driver'));
    }

    public function end(EndTripRequest $request): TripResource
    {
        $trip = $this->liveTripFor($request);
        abort_if($trip === null, 409, 'You have no trip in progress.');

        $collected = $trip->collectedAmount();
        $remitted = $request->filled('remitted_amount')
            ? round((float) $request->input('remitted_amount'), 2)
            : $collected;

        $trip->update([
            'status' => 'Arrived',
            'ended_at' => now(),
            'remitted_amount' => $remitted,
        ]);

        return TripResource::make($trip->fresh()->loadCount('tickets')->load('driver'));
    }

    public function cancel(CancelTripRequest $request): TripResource
    {
        $trip = $this->liveTripFor($request);
        abort_if($trip === null, 409, 'You have no trip in progress.');

        $trip->update([
            'status' => 'Cancelled',
            'cancelled_at' => now(),
            'ended_at' => now(),
            'cancellation_reason' => $request->string('reason')->value(),
        ]);

        return TripResource::make($trip->fresh()->loadCount('tickets')->load('driver'));
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        return TripResource::collection(
            Trip::query()
                ->where('conductor_id', $request->user()->id)
                ->whereIn('status', ['Arrived', 'Cancelled'])
                ->withCount('tickets')
                ->with('driver')
                ->orderByDesc('started_at')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function show(Trip $trip): TripResource
    {
        $this->authorize('view', $trip);

        return TripResource::make($trip->loadCount('tickets')->load(['driver', 'conductor']));
    }

    /** GET /api/conductor/trips/{trip}/remittance — the §4.3 breakdown. */
    public function remittance(Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return response()->json(['data' => TripRemittance::for($trip)->toArray()]);
    }

    private function liveTripFor(Request $request): ?Trip
    {
        return Trip::query()
            ->where('conductor_id', $request->user()->id)
            ->live()
            ->first();
    }
}
