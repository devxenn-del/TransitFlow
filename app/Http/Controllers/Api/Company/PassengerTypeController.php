<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StorePassengerTypeRequest;
use App\Http\Requests\Company\UpdatePassengerTypeRequest;
use App\Http\Resources\PassengerTypeResource;
use App\Models\PassengerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Company passenger types (Regular, Senior, PWD, Articles Sales, …). Their
 * `fare_mode` + `discount_percent` drive the fare a ticket charges (§4.1).
 */
class PassengerTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PassengerType::class);

        return PassengerTypeResource::collection(
            PassengerType::query()
                ->withCount('articles')
                ->with('articles')
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->ordered()
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StorePassengerTypeRequest $request): JsonResponse
    {
        $type = PassengerType::query()->create($request->validated());

        return PassengerTypeResource::make($type->loadCount('articles')->load('articles'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(PassengerType $passengerType): PassengerTypeResource
    {
        $this->authorize('view', $passengerType);

        return PassengerTypeResource::make($passengerType->loadCount('articles')->load('articles'));
    }

    public function update(UpdatePassengerTypeRequest $request, PassengerType $passengerType): PassengerTypeResource
    {
        $passengerType->update($request->validated());

        return PassengerTypeResource::make($passengerType->fresh()->loadCount('articles')->load('articles'));
    }

    public function destroy(PassengerType $passengerType): JsonResponse
    {
        $this->authorize('delete', $passengerType);

        $passengerType->delete(); // articles cascade

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
