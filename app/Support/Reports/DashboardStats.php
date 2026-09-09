<?php

namespace App\Support\Reports;

use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Everything the company dashboard shows, in one place (BITS
 * `App\DashboardStats`, docs/MIGRATION_MAP.md §J).
 *
 * Every figure is company-scoped through the models' global `CompanyScope`;
 * "today" and the 7-day window are in the app timezone (`config/app.php`).
 */
class DashboardStats
{
    /**
     * @return array{
     *     accounts: array{total:int, active:int},
     *     buses: array{total:int, active:int},
     *     routes: array{total:int, active:int},
     *     terminals: array{total:int, active:int},
     *     drivers: array{total:int, active:int},
     *     today: array{trips:int, tickets:int, collected:float},
     *     today_by_method: list<array{method:string, tickets:int, collected:float}>,
     *     weekly_collection: list<array{date:string, label:string, collected:float}>,
     *     recent_trips: list<array<string,mixed>>
     * }
     */
    public function generate(): array
    {
        return [
            'accounts' => $this->countActive(User::query(), 'active'),
            'buses' => $this->countActive(Bus::query()),
            'routes' => $this->countActive(Route::query()),
            'terminals' => $this->countActive(Terminal::query()),
            'drivers' => $this->countActive(Driver::query()),
            'today' => $this->todayOps(),
            'today_by_method' => $this->todayByMethod(),
            'weekly_collection' => $this->weeklyCollection(),
            'recent_trips' => $this->recentTrips(),
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array{total:int, active:int}
     */
    private function countActive($query, string $activeValue = 'Active'): array
    {
        $total = (clone $query)->count();
        $active = (clone $query)->where('status', $activeValue)->count();

        return ['total' => $total, 'active' => $active];
    }

    /**
     * @return array{trips:int, tickets:int, collected:float}
     */
    private function todayOps(): array
    {
        $today = Carbon::today();

        $trips = Trip::query()->whereDate('started_at', $today)->count();

        $tickets = Ticket::query()
            ->whereHas('trip', fn ($q) => $q->whereDate('started_at', $today))
            ->whereNull('refunded_at');

        return [
            'trips' => $trips,
            'tickets' => (clone $tickets)->count(),
            'collected' => round((float) (clone $tickets)->sum('fare'), 2),
        ];
    }

    /**
     * @return list<array{method:string, tickets:int, collected:float}>
     */
    private function todayByMethod(): array
    {
        $today = Carbon::today();

        $rows = Ticket::query()
            ->whereHas('trip', fn ($q) => $q->whereDate('started_at', $today))
            ->whereNull('refunded_at')
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) as tickets, COALESCE(SUM(fare), 0) as collected')
            ->get()
            ->keyBy('payment_method');

        return array_map(fn (string $method) => [
            'method' => $method,
            'tickets' => (int) ($rows[$method]->tickets ?? 0),
            'collected' => round((float) ($rows[$method]->collected ?? 0), 2),
        ], Ticket::PAYMENT_METHODS);
    }

    /**
     * Cash collected per day for the last 7 days (today included), oldest
     * first, days with no trips filled in as 0.
     *
     * @return list<array{date:string, label:string, collected:float}>
     */
    private function weeklyCollection(): array
    {
        $start = Carbon::today()->subDays(6);

        $byDay = Ticket::query()
            ->whereHas('trip', fn ($q) => $q->where('started_at', '>=', $start))
            ->whereNull('refunded_at')
            ->join('trips', 'trips.id', '=', 'tickets.trip_id')
            ->groupBy('day')
            ->selectRaw('DATE(trips.started_at) as day, COALESCE(SUM(tickets.fare), 0) as collected')
            ->pluck('collected', 'day');

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $key = $date->toDateString();
            $week[] = [
                'date' => $key,
                'label' => $date->format('D'),
                'collected' => round((float) ($byDay[$key] ?? 0), 2),
            ];
        }

        return $week;
    }

    /**
     * Most recent trips, newest first, with ticket count + collected split by
     * method + dispatch total.
     *
     * @return list<array<string,mixed>>
     */
    private function recentTrips(int $limit = 8): array
    {
        return Trip::query()
            ->with('conductor:id,name')
            ->withCount('tickets')
            ->withSum('dispatches as dispatch_total', 'amount')
            ->addSelect([
                'collected' => Ticket::query()
                    ->selectRaw('COALESCE(SUM(fare), 0)')
                    ->whereColumn('trip_id', 'trips.id')
                    ->whereNull('refunded_at'),
                'cash_collected' => Ticket::query()
                    ->selectRaw('COALESCE(SUM(fare), 0)')
                    ->whereColumn('trip_id', 'trips.id')
                    ->whereNull('refunded_at')
                    ->where('payment_method', 'Cash'),
                'qr_collected' => Ticket::query()
                    ->selectRaw('COALESCE(SUM(fare), 0)')
                    ->whereColumn('trip_id', 'trips.id')
                    ->whereNull('refunded_at')
                    ->where('payment_method', 'QR'),
            ])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'reference' => $trip->reference,
                'bus_number' => $trip->bus_number,
                'status' => $trip->status,
                'started_at' => $trip->started_at?->toDateTimeString(),
                'origin' => $trip->coverage_origin ?: $trip->origin,
                'destination' => $trip->coverage_destination,
                'conductor_name' => $trip->conductor?->name,
                'ticket_count' => (int) $trip->tickets_count,
                'collected' => round((float) $trip->collected, 2),
                'cash_collected' => round((float) $trip->cash_collected, 2),
                'qr_collected' => round((float) $trip->qr_collected, 2),
                'dispatch_total' => round((float) ($trip->dispatch_total ?? 0), 2),
            ])
            ->all();
    }
}
