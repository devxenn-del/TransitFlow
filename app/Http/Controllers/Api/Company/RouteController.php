<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreRouteRequest;
use App\Http\Requests\Company\UpdateRouteRequest;
use App\Http\Resources\RouteResource;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Routes for the current company. Each row's fare (if any) is eager-loaded
 * so the list can show priced / unpriced at a glance.
 */
class RouteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Route::class);

        return RouteResource::collection(
            Route::query()
                ->with('fareMatrix')
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->boolean('priced_only'), fn ($q) => $q->priced())
                ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                    fn ($s) => $s->where('origin', 'like', "%{$request->string('q')}%")
                        ->orWhere('destination', 'like', "%{$request->string('q')}%")
                        ->orWhere('name', 'like', "%{$request->string('q')}%")
                ))
                ->orderBy('origin')
                ->orderBy('destination')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreRouteRequest $request): JsonResponse
    {
        $route = Route::query()->create([
            ...$request->safe()->except('name'),
            'name' => $request->resolvedName(),
        ]);

        return RouteResource::make($route->load('fareMatrix'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Route $route): RouteResource
    {
        $this->authorize('view', $route);

        return RouteResource::make($route->load('fareMatrix'));
    }

    public function update(UpdateRouteRequest $request, Route $route): RouteResource
    {
        $route->update($request->validated());

        return RouteResource::make($route->fresh()->load('fareMatrix'));
    }

    public function destroy(Route $route): JsonResponse
    {
        $this->authorize('delete', $route);

        $route->delete(); // fare_matrix row cascades

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
