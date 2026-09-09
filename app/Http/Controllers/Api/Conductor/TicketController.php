<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Actions\IssueTicket;
use App\Actions\IssueTicketGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\IssueTicketGroupRequest;
use App\Http\Requests\Conductor\IssueTicketRequest;
use App\Http\Resources\TicketGroupResource;
use App\Http\Resources\TicketResource;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue / list tickets on the conductor's own live trip.
 */
class TicketController extends Controller
{
    public function store(IssueTicketRequest $request, IssueTicket $action): JsonResponse
    {
        $trip = Trip::query()
            ->where('conductor_id', $request->user()->id)
            ->live()
            ->firstOrFail();

        $tickets = $action->handle($trip, $request->validated())
            ->load(['route', 'passengerType']);

        return response()->json([
            'tickets' => TicketResource::collection($tickets),
            'count' => $tickets->count(),
            'total_fare' => round($tickets->sum('fare'), 2),
            'trip' => [
                'id' => $trip->id,
                'collected' => $trip->fresh()->collectedAmount(),
            ],
        ], JsonResponse::HTTP_CREATED);
    }

    public function storeGroup(IssueTicketGroupRequest $request, IssueTicketGroup $action): JsonResponse
    {
        $trip = Trip::query()
            ->where('conductor_id', $request->user()->id)
            ->live()
            ->firstOrFail();

        $group = $action->handle($request->user(), $trip, $request->validated());
        $group->loadMissing('tickets.route', 'tickets.passengerType');

        return response()->json([
            'group' => TicketGroupResource::make($group),
            'trip' => [
                'id' => $trip->id,
                'collected' => $trip->fresh()->collectedAmount(),
            ],
        ], JsonResponse::HTTP_CREATED);
    }

    public function index(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return response()->json([
            'data' => TicketResource::collection(
                $trip->tickets()->with(['route', 'passengerType'])->orderByDesc('id')->get()
            ),
        ]);
    }
}
