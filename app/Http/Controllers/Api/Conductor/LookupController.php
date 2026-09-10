<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Resources\PassengerTypeResource;
use App\Models\Driver;
use App\Models\PassengerType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Terminal;
use App\Models\Trip;
use Illuminate\Http\Request;

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

    public function terminals(): array
    {
        return [
            'data' => Terminal::query()->where('status', 'Active')->orderBy('name')
                ->get(['id', 'name', 'boarding_mode', 'default_route_origin']),
        ];
    }

    /**
     * Distinct priced (origin → destination) pairs — the choices for a
     * trip's declared coverage.
     */
    public function coverageOptions(): array
    {
        $routes = Route::query()->priced()->orderBy('origin')->orderBy('destination')->get(['origin', 'destination']);

        return [
            'origins' => $routes->pluck('origin')->unique()->values(),
            'pairs' => $routes->map(fn ($r) => ['origin' => $r->origin, 'destination' => $r->destination])->values(),
        ];
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
