<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Actions\StartTrip;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\CancelTripRequest;
use App\Http\Requests\Conductor\EndTripRequest;
use App\Http\Requests\Conductor\StartTripRequest;
use App\Http\Resources\CharterBookingResource;
use App\Http\Resources\TripResource;
use App\Models\CharterBooking;
use App\Models\Trip;
use App\Support\OfflineSync;
use App\Support\TripCancellation;
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
            ? TripResource::make($trip->loadCount('tickets')->load(['driver', 'bus', 'charterBooking']))
            : response()->json(['data' => null]);
    }

    /**
     * GET /api/conductor/charters — this conductor's Approved charter bookings
     * still ahead (or under way), soonest first: what a Special trip starts
     * from. Trip details only — never the charter's money.
     */
    public function charters(Request $request): JsonResponse
    {
        $bookings = CharterBooking::query()
            ->where('conductor_id', $request->user()->id)
            ->where('status', 'Approved')
            ->where('return_at', '>', now())
            ->with(['bus', 'driver'])
            ->orderBy('departure_at')
            ->get();

        return response()->json([
            'data' => $bookings->map(fn (CharterBooking $b) => CharterBookingResource::make($b)->withoutLedger()->resolve($request))->values(),
        ]);
    }

    public function start(StartTripRequest $request, StartTrip $action): JsonResponse
    {
        $trip = $action->handle($request->user(), $request->validated());

        return TripResource::make($trip->loadCount('tickets')->load(['driver', 'bus', 'charterBooking']))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function markOnTrip(Request $request): TripResource|JsonResponse
    {
        $trip = $this->liveTripFor($request);
        // Offline Mode replay: the Terminal Receipt was printed on the phone at printed_at.
        $replay = $request->filled('printed_at');

        if ($trip === null || $trip->status !== 'Departure') {
            if ($replay) {
                // Already left the terminal (an earlier replay landed, or the
                // office moved it on) — nothing left to do; the app treats 409 as done.
                return response()->json(['message' => 'This trip has already left the terminal.', 'code' => 'already_done'], 409);
            }
            throw ValidationException::withMessages(['trip' => 'No trip is waiting at the terminal.']);
        }

        $trip->update([
            'status' => 'OnTrip',
            'marked_on_trip_at' => $replay ? OfflineSync::time($request->input('printed_at'), $trip->started_at) : now(),
        ]);

        return TripResource::make($trip->fresh()->loadCount('tickets')->load(['driver', 'bus', 'charterBooking']));
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
            // Offline Mode replay: the trip really ended on the phone at ended_at.
            'ended_at' => OfflineSync::time($request->input('ended_at'), $trip->marked_on_trip_at ?? $trip->started_at),
            'remitted_amount' => $remitted,
        ]);

        return TripResource::make($trip->fresh()->loadCount('tickets')->load(['driver', 'bus', 'charterBooking']));
    }

    /**
     * GET /api/conductor/trips/cancel-preview?stop= — every live ticket's
     * refund if the trip were cancelled at that stop (App\Support\TripCancellation).
     */
    public function cancelPreview(Request $request): JsonResponse
    {
        $trip = $this->liveTripFor($request);
        abort_if($trip === null, 409, 'You have no trip in progress.');

        return response()->json(['data' => TripCancellation::for($trip)->preview($request->query('stop'))]);
    }

    public function cancel(CancelTripRequest $request): TripResource
    {
        $trip = $this->liveTripFor($request);
        abort_if($trip === null, 409, 'You have no trip in progress.');

        $summary = TripCancellation::for($trip)->cancel(
            $request->string('reason')->value(),
            $request->input('cancel_stop'),
            (array) $request->input('refund_ticket_ids', []),
        );

        return TripResource::make($trip->fresh()->loadCount('tickets')->load(['driver', 'bus', 'charterBooking']))
            ->additional(['meta' => $summary]);
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        return TripResource::collection(
            Trip::query()
                ->where('conductor_id', $request->user()->id)
                ->whereIn('status', ['Arrived', 'Cancelled'])
                ->withCount('tickets')
                ->with(['driver', 'bus', 'charterBooking'])
                ->orderByDesc('started_at')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function show(Trip $trip): TripResource
    {
        $this->authorize('view', $trip);

        return TripResource::make($trip->loadCount('tickets')->load(['driver', 'conductor', 'bus']));
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
