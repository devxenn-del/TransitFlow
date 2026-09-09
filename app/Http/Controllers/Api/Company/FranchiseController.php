<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreFranchiseRequest;
use App\Http\Requests\Company\UpdateFranchiseRequest;
use App\Http\Resources\FranchiseResource;
use App\Models\Franchise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * LTFRB franchises for the current company. Each row carries counts (stops,
 * routes, priced routes) so the Fare Matrix landing table can show them at
 * a glance; the grid itself is served by FareMatrixGridController.
 */
class FranchiseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Franchise::class);

        $franchises = Franchise::query()
            ->withCount([
                'stops',
                'routes',
                'routes as priced_routes_count' => fn ($q) => $q->whereHas('fareMatrix', fn ($f) => $f->where('status', 'Active')),
            ])
            ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                fn ($s) => $s->where('applicant_name', 'like', "%{$request->string('q')}%")
                    ->orWhere('route_description', 'like', "%{$request->string('q')}%")
                    ->orWhere('case_no', 'like', "%{$request->string('q')}%")
            ))
            ->orderBy('applicant_name')
            ->paginate($request->integer('per_page', 20));

        return FranchiseResource::collection($franchises);
    }

    public function store(StoreFranchiseRequest $request): JsonResponse
    {
        $franchise = Franchise::query()->create($request->validated());

        return FranchiseResource::make($franchise)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Franchise $franchise): FranchiseResource
    {
        $this->authorize('view', $franchise);

        return FranchiseResource::make($franchise->loadCount(['stops', 'routes']));
    }

    public function update(UpdateFranchiseRequest $request, Franchise $franchise): FranchiseResource
    {
        $franchise->update($request->validated());

        return FranchiseResource::make($franchise->fresh());
    }

    public function destroy(Franchise $franchise): JsonResponse
    {
        $this->authorize('delete', $franchise);

        $franchise->delete(); // stops + routes + fares cascade

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
