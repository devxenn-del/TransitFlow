<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\IssueDispatchRequest;
use App\Http\Resources\DispatchResource;
use App\Models\Trip;
use App\Support\OfflineSync;
use App\Support\TripRemittance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Barker (terminal dispatcher) payouts recorded by the conductor against
 * their own live trip — BITS conductor/dispatch + api/dispatch/* (§4.3).
 * The trip is always the authenticated conductor's live trip; its id is
 * never taken from the request.
 */
class DispatchController extends Controller
{
    public function store(IssueDispatchRequest $request): JsonResponse
    {
        // A live trip — or, for an Offline Mode replay naming trip_id, that
        // trip even if it has ended since (App\Support\OfflineSync).
        $trip = OfflineSync::tripForSale($request, $request->user());
        abort_if($trip === null, 404, 'You have no trip in progress.');

        $clientUuid = trim((string) $request->input('client_uuid', '')) ?: null;
        $existing = $clientUuid !== null
            ? $trip->dispatches()->where('client_uuid', $clientUuid)->first()
            : null;

        $replay = OfflineSync::isReplay($request);
        $dispatch = $existing ?? $trip->dispatches()->create([
            'company_id' => $trip->company_id,
            'barker_name' => $request->string('barker_name')->trim()->value(),
            'amount' => round((float) $request->input('amount'), 2),
            'dispatched_at' => $replay ? OfflineSync::time($request->input('dispatched_at'), $trip->started_at) : now(),
            'client_uuid' => $clientUuid,
            'offline_sync' => $replay,
        ]);

        return response()->json([
            'data' => DispatchResource::make($dispatch),
            'remittance' => TripRemittance::for($trip->fresh())->toArray(),
        ], $existing !== null ? JsonResponse::HTTP_OK : JsonResponse::HTTP_CREATED);
    }

    public function index(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return response()->json([
            'data' => DispatchResource::collection(
                $trip->dispatches()->orderByDesc('id')->get()
            ),
            'total' => round((float) $trip->dispatches()->sum('amount'), 2),
        ]);
    }

    public function destroy(Request $request, Trip $trip, int $dispatch): JsonResponse
    {
        abort_unless($trip->conductor_id === $request->user()->id, 403);
        abort_unless($trip->isLive(), 409, 'The trip has ended — dispatches are locked.');

        $row = $trip->dispatches()->findOrFail($dispatch);
        $row->delete();

        return response()->json([
            'remittance' => TripRemittance::for($trip->fresh())->toArray(),
        ]);
    }
}
