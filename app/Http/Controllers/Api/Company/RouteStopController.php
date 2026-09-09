<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SaveRouteStopsRequest;
use App\Http\Resources\RouteStopResource;
use App\Models\Franchise;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages a franchise's ordered stop list — the axes of its fare-matrix
 * grid. `save` replaces the whole list in one call (BITS' "Reorder Stops"
 * modal), setting `sort_order` from the given order.
 */
class RouteStopController extends Controller
{
    public function index(Franchise $franchise): AnonymousResourceCollection
    {
        $this->authorize('view', $franchise);

        return RouteStopResource::collection($franchise->stops()->get());
    }

    /**
     * PUT /api/company/franchises/{franchise}/stops
     * body: { stops: ["SM PALA-PALA", "LANGKAAN", ...] }  (in display order)
     */
    public function save(SaveRouteStopsRequest $request, Franchise $franchise): AnonymousResourceCollection
    {
        $this->authorize('update', $franchise);

        /** @var list<string> $names */
        $names = collect($request->validated('stops'))
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $current = $franchise->stops()->pluck('name')->all();
        $removed = array_diff($current, $names);

        if ($removed !== []) {
            // Routes touching a dropped stop. A fare-less route is a phantom
            // grid cell and is removed with the stop; a route that still has
            // a fare blocks removal unless `force` is set (then its fare is
            // cleared too).
            $touchingRemoved = Route::withoutGlobalScopes()
                ->where('franchise_id', $franchise->id)
                ->where(fn ($q) => $q->whereIn('origin', $removed)->orWhereIn('destination', $removed));

            $priced = (clone $touchingRemoved)->whereHas('fareMatrix')->get(['id', 'origin', 'destination']);

            if ($priced->isNotEmpty() && ! $request->boolean('force')) {
                $sample = $priced->take(6)->map(fn ($r) => "{$r->origin} → {$r->destination}")->implode(', ');
                $more = $priced->count() > 6 ? ' and '.($priced->count() - 6).' more' : '';

                throw ValidationException::withMessages([
                    'stops' => "Can't remove a stop that still has fares: {$sample}{$more}. Clear them first, or confirm to clear and remove.",
                ]);
            }

            DB::transaction(function () use ($franchise, $names, $removed, $touchingRemoved): void {
                (clone $touchingRemoved)->delete(); // fares cascade with the route
                $franchise->stops()->whereIn('name', $removed)->delete();
                $this->syncOrder($franchise, $names);
            });

            return RouteStopResource::collection($franchise->stops()->get());
        }

        DB::transaction(fn () => $this->syncOrder($franchise, $names));

        return RouteStopResource::collection($franchise->stops()->get());
    }

    /**
     * @param  list<string>  $names
     */
    private function syncOrder(Franchise $franchise, array $names): void
    {
        foreach ($names as $i => $name) {
            RouteStop::withoutGlobalScopes()->updateOrCreate(
                ['franchise_id' => $franchise->id, 'name' => $name],
                ['company_id' => $franchise->company_id, 'sort_order' => $i + 1, 'status' => 'Active'],
            );
        }
    }
}
