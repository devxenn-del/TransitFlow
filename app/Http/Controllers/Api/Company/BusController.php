<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreBusRequest;
use App\Http\Requests\Company\UpdateBusRequest;
use App\Http\Resources\BusResource;
use App\Models\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Buses for the current company. Every query runs through CompanyScope, so
 * this controller never has to filter by `company_id` itself and a bus
 * belonging to another company is simply not found (404) here — even for a
 * request that hand-crafts the id.
 */
class BusController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Bus::class);

        return BusResource::collection(
            Bus::query()
                ->with(['driver', 'conductors'])
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->string('vehicle_type')->isNotEmpty(), fn ($q) => $q->where('vehicle_type', $request->string('vehicle_type')))
                ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                    fn ($s) => $s->where('bus_number', 'like', "%{$request->string('q')}%")
                        ->orWhere('plate_number', 'like', "%{$request->string('q')}%")
                        ->orWhere('model', 'like', "%{$request->string('q')}%")
                ))
                ->orderBy('bus_number')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreBusRequest $request): JsonResponse
    {
        // company_id is filled in by the BelongsToCompany trait. Refresh so
        // DB-applied column defaults (status, vehicle_type) are reflected in
        // the response when the caller omitted them, not just in storage.
        $bus = Bus::query()->create($request->safe()->except(['driver_id', 'conductor_ids']));
        $this->assignCrew($request, $bus);

        return BusResource::make($bus->fresh()->load(['driver', 'conductors']))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Bus $bus): BusResource
    {
        $this->authorize('view', $bus);

        return BusResource::make($bus->load(['driver', 'conductors']));
    }

    public function update(UpdateBusRequest $request, Bus $bus): BusResource
    {
        $bus->update($request->safe()->except(['driver_id', 'conductor_ids']));
        $this->assignCrew($request, $bus);

        return BusResource::make($bus->fresh()->load(['driver', 'conductors']));
    }

    public function destroy(Bus $bus): JsonResponse
    {
        $this->authorize('delete', $bus);

        $bus->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Apply the optional crew fields: the regular driver (released from any
     * other bus first) and the assigned conductor accounts.
     */
    private function assignCrew(StoreBusRequest|UpdateBusRequest $request, Bus $bus): void
    {
        if ($request->has('driver_id')) {
            $bus->assignDriver($request->validated('driver_id'));
        }

        if ($request->has('conductor_ids')) {
            $bus->conductors()->sync($request->validated('conductor_ids'));
        }
    }
}
