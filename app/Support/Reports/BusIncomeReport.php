<?php

namespace App\Support\Reports;

use App\Models\Bus;
use Illuminate\Support\Carbon;

/**
 * Per-bus fare income for a `daily` / `weekly` / `monthly` window — the
 * "Income Monitoring" summary (BITS `App\BusIncomeReport`, docs/MIGRATION_MAP.md §J).
 *
 * Every bus in the company appears, even one with no activity in the window
 * (it shows zeros) — the date filter lives in the JOIN, not a WHERE, so a
 * quiet bus never drops off the report.
 *
 *   trip_count   = DISTINCT trips that sold at least one ticket in the window
 *   ticket_count = ticket rows issued in the window
 *   income       = SUM(tickets.fare) in the window
 *
 * Company scoping comes from `Bus`'s global `CompanyScope`; the report never
 * takes a company id of its own.
 */
class BusIncomeReport
{
    /**
     * @return array{
     *     period: string,
     *     range_label: string,
     *     range_start: string,
     *     range_end: string,
     *     rows: list<array{bus_id:int, bus_number:string, plate_number:string, trip_count:int, ticket_count:int, income:float}>,
     *     total_income: float,
     *     total_trips: int,
     *     total_tickets: int
     * }
     */
    public function generate(string $period, string|Carbon|null $referenceDate = null): array
    {
        $range = ReportPeriod::resolve($period, $referenceDate);

        $query = Bus::query()
            ->leftJoin('trips', 'trips.bus_id', '=', 'buses.id')
            ->leftJoin('tickets', function ($join) use ($range) {
                $join->on('tickets.trip_id', '=', 'trips.id')
                    ->where('tickets.issued_at', '>=', $range->start)
                    ->where('tickets.issued_at', '<', $range->end);
            })
            ->groupBy('buses.id', 'buses.bus_number', 'buses.plate_number')
            ->orderBy('buses.bus_number')
            ->selectRaw('buses.id as bus_id, buses.bus_number, buses.plate_number')
            ->selectRaw('COUNT(DISTINCT CASE WHEN tickets.id IS NOT NULL THEN trips.id END) as trip_count')
            ->selectRaw('COUNT(tickets.id) as ticket_count')
            ->selectRaw('COALESCE(SUM(tickets.fare), 0) as income');

        $rows = [];
        $totalIncome = 0.0;
        $totalTrips = 0;
        $totalTickets = 0;

        foreach ($query->get() as $row) {
            $entry = [
                'bus_id' => (int) $row->bus_id,
                'bus_number' => (string) $row->bus_number,
                'plate_number' => (string) $row->plate_number,
                'trip_count' => (int) $row->trip_count,
                'ticket_count' => (int) $row->ticket_count,
                'income' => (float) $row->income,
            ];

            $totalIncome += $entry['income'];
            $totalTrips += $entry['trip_count'];
            $totalTickets += $entry['ticket_count'];

            $rows[] = $entry;
        }

        return $range->toArray() + [
            'rows' => $rows,
            'total_income' => round($totalIncome, 2),
            'total_trips' => $totalTrips,
            'total_tickets' => $totalTickets,
        ];
    }
}
