<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\RecordBusLocationRequest;
use App\Models\BusLocation;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * GPS position ingest from a conductor's device — BITS
 * `api/trips/updateLocation.php` (docs/MIGRATION_MAP.md §H).
 *
 * A fix is accepted only while the authenticated conductor has a live trip
 * on the bus named in the request; the trip id is never taken from the
 * client. The same endpoint backs the SPA and the future mobile app.
 */
class LocationController extends Controller
{
    public function store(RecordBusLocationRequest $request): JsonResponse
    {
        $trip = Trip::query()
            ->where('conductor_id', $request->user()->id)
            ->live()
            ->first();

        abort_if($trip === null, 409, 'You have no live trip.');
        abort_unless(
            (int) $trip->bus_id === $request->integer('bus_id'),
            422,
            'That bus is not on your live trip.',
        );

        $recordedAt = $request->filled('recorded_at')
            ? Carbon::parse($request->input('recorded_at'))
            : now();

        // Never trust a client clock that is in the future or wildly stale.
        if ($recordedAt->isFuture() || $recordedAt->lt(now()->subHour())) {
            $recordedAt = now();
        }

        $row = BusLocation::query()->create([
            'company_id' => $trip->company_id,
            'trip_id' => $trip->id,
            'bus_id' => $trip->bus_id,
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'speed_kph' => $request->input('speed_kph'),
            'heading' => $request->input('heading'),
            'recorded_at' => $recordedAt,
        ]);

        return response()->json([
            'data' => [
                'trip_id' => $trip->id,
                'recorded_at' => $row->recorded_at->toDateTimeString(),
            ],
        ], JsonResponse::HTTP_CREATED);
    }
}
