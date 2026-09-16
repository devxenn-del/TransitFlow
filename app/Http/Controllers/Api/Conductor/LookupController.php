<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Resources\PassengerTypeResource;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Terminal;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The reference data the conductor's Start-Trip and Ticketing forms need.
 */
class LookupController extends Controller
{
    /** The conductor's own assigned, Active buses. */
    public function assignedBuses(Request $request): array
    {
        return [
            'data' => $request->user()->buses()
                ->where('status', 'Active')
                ->orderBy('bus_number')
                ->get(['buses.id', 'bus_number', 'plate_number', 'capacity']),
        ];
    }

    public function drivers(): array
    {
        return [
            'data' => Driver::query()->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'employee_id']),
        ];
    }

    /**
     * Ordered by each terminal's mapped route-stop position (falling back to
     * its own name when it has no default_route_origin), not alphabetically —
     * terminals aren't franchise-scoped, so this uses one global stop-name ->
     * sort_order lookup rather than the per-franchise resolution
     * coverageOptions() needs.
     */
    public function terminals(): array
    {
        $terminals = Terminal::query()->where('status', 'Active')
            ->get(['id', 'name', 'boarding_mode', 'default_route_origin']);

        $stops = $this->stopLookup(RouteStop::query()->orderBy('sort_order')->get(['name', 'sort_order']));

        return [
            'data' => $terminals->map(fn ($terminal) => [
                $terminal,
                $stops->get($this->normalizeStopName($terminal->default_route_origin ?: $terminal->name)),
            ])->sortBy(fn ($pair) => $pair[1]['sort_order'] ?? PHP_INT_MAX)
                ->map(function ($pair) {
                    $pair[0]->setAttribute('resolved_stop', $pair[1]['name'] ?? null);

                    return $pair[0];
                })->values(),
        ];
    }

    /**
     * Distinct priced (origin → destination) pairs — the choices for a
     * trip's declared coverage, ordered by each route's own franchise stop
     * sequence (route_stops.sort_order) rather than alphabetically, so
     * Origin/Destination pickers read in actual geographic/travel order —
     * same ordering rule as stops() below, just spanning every priced route
     * instead of one trip's.
     */
    public function coverageOptions(): array
    {
        $routes = Route::query()->priced()->get(['origin', 'destination', 'franchise_id']);

        $stopOrder = RouteStop::query()
            ->whereIn('franchise_id', $routes->pluck('franchise_id')->unique())
            ->orderBy('sort_order')
            ->get(['franchise_id', 'name', 'sort_order'])
            ->groupBy('franchise_id')
            ->map(fn ($stops) => $stops->pluck('sort_order', 'name'));

        $orderOf = fn ($route, string $stopName) => $stopOrder->get($route->franchise_id)?->get($stopName) ?? PHP_INT_MAX;

        $routes = $routes->sort(function ($a, $b) use ($orderOf) {
            $originCompare = $orderOf($a, $a->origin) <=> $orderOf($b, $b->origin);

            return $originCompare !== 0 ? $originCompare : $orderOf($a, $a->destination) <=> $orderOf($b, $b->destination);
        })->values();

        $origins = collect();
        foreach ($routes as $route) {
            if (! $origins->contains($route->origin)) {
                $origins->push($route->origin);
            }
        }

        return [
            'origins' => $origins->values(),
            'pairs' => $routes->map(fn ($r) => ['origin' => $r->origin, 'destination' => $r->destination])->values(),
        ];
    }

    /** Every Active franchise ("route") this company operates — the Start
     *  Trip screen's Select Route step, before any origin/terminal is chosen. */
    public function routes(): array
    {
        return [
            'data' => Franchise::query()->where('status', 'Active')
                ->orderBy('route_description')
                ->get(['id', 'route_description', 'route_origin', 'route_destination']),
        ];
    }

    /**
     * A single franchise's priced (origin → destination) pairs, ordered by
     * its own route_stops.sort_order — the same shape as coverageOptions()
     * above, just scoped to one route instead of every priced route company-
     * wide, for the Start Trip screen once a route is picked. Also carries
     * the franchise's configured default origin, so the app can preselect it
     * rather than leaving the conductor to guess.
     */
    public function routeCoverage(Franchise $franchise): array
    {
        $this->authorize('view', $franchise);

        $routes = Route::query()->where('franchise_id', $franchise->id)->priced()->get(['origin', 'destination']);
        $stopOrder = RouteStop::query()->where('franchise_id', $franchise->id)->orderBy('sort_order')->pluck('sort_order', 'name');

        $sorted = $this->sortByStopOrder($routes, $stopOrder);

        $origins = collect();
        foreach ($sorted as $route) {
            if (! $origins->contains($route->origin)) {
                $origins->push($route->origin);
            }
        }

        return [
            'default_origin' => $franchise->route_origin,
            'origins' => $origins->values(),
            'pairs' => $sorted->map(fn ($r) => ['origin' => $r->origin, 'destination' => $r->destination])->values(),
        ];
    }

    /**
     * Terminals relevant to one franchise — any Active terminal whose mapped
     * stop (default_route_origin, falling back to its own name) is actually
     * on this route, ordered by that stop's position. Used for the Start
     * Trip screen's "At Terminal = Yes" mode, so a conductor is never offered
     * a terminal that has nothing to do with the route they picked.
     *
     * Matching is whitespace/case-insensitive (see normalizeStopName()) —
     * terminals.name and route_stops.name are entered independently by admins
     * and can drift ("EPZA (ROSARIO)" vs "EPZA(ROSARIO)") without meaning a
     * different place. Each returned terminal carries a resolved_stop field:
     * the route_stop's own, canonical spelling, which is what the app must
     * send back as coverage_origin/coverage_destination — never the
     * terminal's own name — so fare lookups (which key off route_stops'
     * spelling) don't fail over the same drift.
     */
    public function routeTerminals(Franchise $franchise): array
    {
        $this->authorize('view', $franchise);

        $stops = $this->stopLookup(RouteStop::query()->where('franchise_id', $franchise->id)->orderBy('sort_order')->get(['name', 'sort_order']));

        $terminals = Terminal::query()->where('status', 'Active')
            ->get(['id', 'name', 'boarding_mode', 'default_route_origin'])
            ->map(fn ($terminal) => [
                $terminal,
                $stops->get($this->normalizeStopName($terminal->default_route_origin ?: $terminal->name)),
            ])
            ->filter(fn ($pair) => $pair[1] !== null)
            ->sortBy(fn ($pair) => $pair[1]['sort_order'])
            ->map(function ($pair) {
                $pair[0]->setAttribute('resolved_stop', $pair[1]['name']);

                return $pair[0];
            })
            ->values();

        return ['data' => $terminals];
    }

    /**
     * Stop names keyed by a whitespace/case-insensitive normalization of
     * themselves, so a terminal's own (possibly differently formatted) name
     * can still find its real route_stop match. Duplicates keep the first
     * (lowest sort_order) occurrence.
     *
     * @param  Collection<int, RouteStop>  $stops
     * @return Collection<string, array{name: string, sort_order: int}>
     */
    private function stopLookup(Collection $stops): Collection
    {
        $out = collect();
        foreach ($stops as $stop) {
            $key = $this->normalizeStopName($stop->name);
            if (! $out->has($key)) {
                $out->put($key, ['name' => $stop->name, 'sort_order' => $stop->sort_order]);
            }
        }

        return $out;
    }

    private function normalizeStopName(?string $name): string
    {
        return strtoupper(preg_replace('/\s+/', '', $name ?? ''));
    }

    /**
     * @param  Collection<int, Route>  $routes
     * @param  Collection<string, int>  $stopOrder  stop name -> sort_order
     * @return Collection<int, Route>
     */
    private function sortByStopOrder(Collection $routes, Collection $stopOrder): Collection
    {
        return $routes->sort(function ($a, $b) use ($stopOrder) {
            $originCompare = ($stopOrder->get($a->origin) ?? PHP_INT_MAX) <=> ($stopOrder->get($b->origin) ?? PHP_INT_MAX);

            return $originCompare !== 0
                ? $originCompare
                : ($stopOrder->get($a->destination) ?? PHP_INT_MAX) <=> ($stopOrder->get($b->destination) ?? PHP_INT_MAX);
        })->values();
    }

    /**
     * The priced fares available on a given trip — every priced route whose
     * origin is the trip's coverage origin (Via Terminal), plus all priced
     * routes for Via Pick-up.
     */
    public function tripFares(Trip $trip): array
    {
        $this->authorize('view', $trip);

        $origin = $trip->coverage_origin !== '' ? $trip->coverage_origin : $trip->origin;

        $terminal = Route::query()->priced()->where('origin', $origin)
            ->with('fareMatrix')->orderBy('destination')->get();
        $pickup = Route::query()->priced()->with('fareMatrix')->orderBy('origin')->orderBy('destination')->get();

        $map = fn ($routes) => $routes->map(fn ($r) => [
            'route_id' => $r->id,
            'origin' => $r->origin,
            'destination' => $r->destination,
            'amount' => (float) $r->fareMatrix->amount,
        ])->values();

        return ['terminal' => $map($terminal), 'pickup' => $map($pickup)];
    }

    public function passengerTypes(): array
    {
        return [
            'data' => PassengerTypeResource::collection(
                PassengerType::query()->where('status', 'Active')->with('articles')->ordered()->get()
            ),
        ];
    }

    /**
     * The trip's route stops in franchise order, clamped to the inclusive
     * span between its coverage origin and destination, plus each stop's
     * index — the conductor app's "STOP N OF M" / forward-only destination
     * picker. Franchise is resolved from whichever priced Route matches the
     * trip's declared coverage. If either endpoint can't be located in the
     * franchise's stop list (stale data, no matching route), this fails
     * open and returns the franchise's full stop list rather than blocking
     * ticket sales over a missing sequence.
     */
    public function stops(Trip $trip): array
    {
        $this->authorize('view', $trip);

        $origin = $trip->coverage_origin;
        $destination = $trip->coverage_destination;

        $route = Route::query()
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->first();

        $stopNames = $route
            ? RouteStop::query()->where('franchise_id', $route->franchise_id)
                ->orderBy('sort_order')->pluck('name')->all()
            : [];

        $originIndex = array_search($origin, $stopNames, true);
        $destinationIndex = array_search($destination, $stopNames, true);

        if ($stopNames === [] || $originIndex === false || $destinationIndex === false) {
            $stops = collect($stopNames)->values()
                ->map(fn (string $name, int $i) => ['name' => $name, 'index' => $i])->all();

            return [
                'origin_index' => $originIndex !== false ? $originIndex : null,
                'destination_index' => $destinationIndex !== false ? $destinationIndex : null,
                'direction' => 'forward',
                'stops' => $stops,
            ];
        }

        $direction = $destinationIndex >= $originIndex ? 'forward' : 'backward';
        $lo = min($originIndex, $destinationIndex);
        $hi = max($originIndex, $destinationIndex);

        $stops = [];
        for ($i = $lo; $i <= $hi; $i++) {
            $stops[] = ['name' => $stopNames[$i], 'index' => $i];
        }

        return [
            'origin_index' => $originIndex,
            'destination_index' => $destinationIndex,
            'direction' => $direction,
            'stops' => $stops,
        ];
    }
}
