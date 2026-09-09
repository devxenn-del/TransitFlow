<?php

namespace App\Support;

use App\Models\BusLocation;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Support\Carbon;

/**
 * The office live-monitoring payload — BITS `office/dashboard.php` +
 * `App\OfficeDashboardStats` + the fleet map (docs/MIGRATION_MAP.md §H).
 *
 * Read by `GET /api/company/live`, which the SPA polls every ~3 s (the
 * transport decision resolved to polling, not Reverb). Every figure is
 * company-scoped through the models' global `CompanyScope`.
 */
class LiveBoard
{
    /**
     * @return array{
     *     counters: array{live:int, on_trip:int, at_terminal:int, arrived_today:int, stale:int},
     *     live_trips: list<array<string,mixed>>,
     *     arrived_today: list<array<string,mixed>>,
     *     recent_trips: list<array<string,mixed>>,
     *     generated_at: string
     * }
     */
    public function generate(): array
    {
        $liveTrips = $this->liveTrips();
        $arrivedToday = $this->arrivedToday();

        $staleCount = 0;
        foreach ($liveTrips as $trip) {
            if ($trip['location'] === null || $trip['location']['is_stale']) {
                $staleCount++;
            }
        }

        return [
            'counters' => [
                'live' => count($liveTrips),
                'on_trip' => count(array_filter($liveTrips, fn ($t) => $t['status'] === 'OnTrip')),
                'at_terminal' => count(array_filter($liveTrips, fn ($t) => $t['status'] === 'Departure')),
                'arrived_today' => count($arrivedToday),
                'stale' => $staleCount,
            ],
            'live_trips' => $liveTrips,
            'arrived_today' => $arrivedToday,
            'recent_trips' => $this->recentTrips(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function liveTrips(): array
    {
        $trips = Trip::query()
            ->live()
            ->with(['conductor:id,name', 'bus:id,bus_number,plate_number'])
            ->withCount(['tickets as ticket_count' => fn ($q) => $q->whereNull('refunded_at')])
            ->withSum(['tickets as collected' => fn ($q) => $q->whereNull('refunded_at')], 'fare')
            ->orderBy('started_at')
            ->get();

        $latest = $this->latestLocations($trips->pluck('id')->all());

        return $trips->map(function (Trip $trip) use ($latest) {
            $location = $latest[$trip->id] ?? null;

            return [
                'trip_id' => $trip->id,
                'reference' => $trip->reference,
                'status' => $trip->status,
                'bus_number' => $trip->bus?->bus_number ?? $trip->bus_number,
                'plate_number' => $trip->bus?->plate_number,
                'conductor_name' => $trip->conductor?->name,
                'origin' => $trip->coverage_origin ?: $trip->origin,
                'destination' => $trip->coverage_destination,
                'started_at' => $trip->started_at?->toDateTimeString(),
                'marked_on_trip_at' => $trip->marked_on_trip_at?->toDateTimeString(),
                'ticket_count' => (int) $trip->ticket_count,
                'collected' => round((float) $trip->collected, 2),
                'location' => $location === null ? null : [
                    'lat' => (float) $location->lat,
                    'lng' => (float) $location->lng,
                    'speed_kph' => $location->speed_kph !== null ? (float) $location->speed_kph : null,
                    'heading' => $location->heading,
                    'recorded_at' => $location->recorded_at?->toDateTimeString(),
                    'is_stale' => $location->isStale(),
                ],
            ];
        })->all();
    }

    /**
     * Latest position per trip for the given trip ids.
     *
     * @param  list<int>  $tripIds
     * @return array<int, BusLocation>
     */
    private function latestLocations(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return BusLocation::query()
            ->whereIn('trip_id', $tripIds)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->unique('trip_id')
            ->keyBy('trip_id')
            ->all();
    }

    /**
     * Trips that arrived during today's operational date, newest first —
     * the "just came in, awaiting remittance" column.
     *
     * @return list<array<string,mixed>>
     */
    private function arrivedToday(): array
    {
        return Trip::query()
            ->where('status', 'Arrived')
            ->whereDate('op_date', Carbon::today())
            ->with(['conductor:id,name', 'bus:id,bus_number'])
            ->orderByDesc('ended_at')
            ->limit(30)
            ->get()
            ->map(fn (Trip $trip) => [
                'trip_id' => $trip->id,
                'reference' => $trip->reference,
                'bus_number' => $trip->bus?->bus_number ?? $trip->bus_number,
                'conductor_name' => $trip->conductor?->name,
                'ended_at' => $trip->ended_at?->toDateTimeString(),
                'collected' => round((float) $trip->collectedAmount(), 2),
                'remitted_amount' => $trip->remitted_amount !== null ? round((float) $trip->remitted_amount, 2) : null,
                'remittance_stage' => $this->remittanceStage($trip),
            ])
            ->all();
    }

    private function remittanceStage(Trip $trip): string
    {
        if ($trip->remittance_approved_at !== null) {
            return 'approved';
        }

        if ($trip->remittance_received_at !== null) {
            return 'received';
        }

        return 'pending';
    }

    /**
     * Newest 6 trips across every status — the Admin "Recent Trips" card.
     *
     * @return list<array<string,mixed>>
     */
    private function recentTrips(): array
    {
        return Trip::query()
            ->with(['conductor:id,name', 'bus:id,bus_number'])
            ->withCount(['tickets as ticket_count' => fn ($q) => $q->whereNull('refunded_at')])
            ->addSelect([
                'collected' => Ticket::query()
                    ->selectRaw('COALESCE(SUM(fare), 0)')
                    ->whereColumn('trip_id', 'trips.id')
                    ->whereNull('refunded_at'),
            ])
            ->orderByDesc('started_at')
            ->limit(6)
            ->get()
            ->map(fn (Trip $trip) => [
                'trip_id' => $trip->id,
                'reference' => $trip->reference,
                'status' => $trip->status,
                'bus_number' => $trip->bus?->bus_number ?? $trip->bus_number,
                'conductor_name' => $trip->conductor?->name,
                'started_at' => $trip->started_at?->toDateTimeString(),
                'ticket_count' => (int) $trip->ticket_count,
                'collected' => round((float) $trip->collected, 2),
            ])
            ->all();
    }
}
