<?php

namespace App\Support\Reports;

use App\Models\Bus;
use App\Models\Trip;
use Illuminate\Support\Carbon;

/**
 * Per-trip "Income Monitoring" breakdown for one bus on one operational date
 * — every trip that bus ran that day, passenger counts split Terminal vs
 * Pickup, fare revenue, dispatch (barker) payout, and net income (BITS
 * `App\TripIncomeReport`, docs/MIGRATION_MAP.md §J).
 *
 *   net_total  = fare_total − dispatch_total     (dispatch is a payout, never income)
 *
 * Company scoping comes from the models' global `CompanyScope`.
 */
class TripIncomeReport
{
    /**
     * @return array{
     *     bus_id: int, bus_number: string, plate_number: string, date: string,
     *     rows: list<array{
     *         trip_id:int, reference:?string, started_at:?string, ended_at:?string,
     *         origin:string, destination:string,
     *         terminal_count:int, pickup_count:int, passenger_count:int,
     *         terminal_fare_total:float, pickup_fare_total:float,
     *         fare_total:float, dispatch_total:float,
     *         excess_remit:float, short_remit:float, net_total:float
     *     }>,
     *     total_passengers:int, total_dispatch:float,
     *     total_excess_remit:float, total_short_remit:float,
     *     total_received:float, total_income:float
     * }|null
     */
    public function generate(int $busId, string|Carbon $date): ?array
    {
        $bus = Bus::query()->find($busId);
        if ($bus === null) {
            return null;
        }

        $date = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $trips = Trip::query()
            ->where('bus_id', $busId)
            ->whereDate('op_date', $date)
            ->withCount([
                'tickets as terminal_count' => fn ($q) => $q->where('boarding_type', 'Terminal')->whereNull('refunded_at'),
                'tickets as pickup_count' => fn ($q) => $q->where('boarding_type', 'Pickup')->whereNull('refunded_at'),
            ])
            ->withSum(['tickets as terminal_fare_total' => fn ($q) => $q->where('boarding_type', 'Terminal')->whereNull('refunded_at')], 'fare')
            ->withSum(['tickets as pickup_fare_total' => fn ($q) => $q->where('boarding_type', 'Pickup')->whereNull('refunded_at')], 'fare')
            ->withSum(['tickets as fare_total' => fn ($q) => $q->whereNull('refunded_at')], 'fare')
            ->withSum('dispatches as dispatch_total', 'amount')
            ->orderBy('started_at')
            ->get();

        $rows = [];
        $totalPassengers = 0;
        $totalDispatch = 0.0;
        $totalReceived = 0.0;
        $totalExcess = 0.0;
        $totalShort = 0.0;

        foreach ($trips as $trip) {
            $terminalCount = (int) $trip->terminal_count;
            $pickupCount = (int) $trip->pickup_count;
            $fareTotal = (float) ($trip->fare_total ?? 0);
            $dispatchTotal = (float) ($trip->dispatch_total ?? 0);
            $excess = (float) ($trip->remittance_excess_amount ?? 0);
            $short = (float) ($trip->remittance_short_amount ?? 0);
            $passengerCount = $terminalCount + $pickupCount;

            $totalPassengers += $passengerCount;
            $totalDispatch += $dispatchTotal;
            $totalReceived += $fareTotal;
            $totalExcess += $excess;
            $totalShort += $short;

            $rows[] = [
                'trip_id' => $trip->id,
                'reference' => $trip->reference,
                'started_at' => $trip->started_at?->toDateTimeString(),
                'ended_at' => $trip->ended_at?->toDateTimeString(),
                'origin' => (string) ($trip->coverage_origin ?: $trip->origin),
                'destination' => (string) $trip->coverage_destination,
                'terminal_count' => $terminalCount,
                'pickup_count' => $pickupCount,
                'passenger_count' => $passengerCount,
                'terminal_fare_total' => round((float) ($trip->terminal_fare_total ?? 0), 2),
                'pickup_fare_total' => round((float) ($trip->pickup_fare_total ?? 0), 2),
                'fare_total' => round($fareTotal, 2),
                'dispatch_total' => round($dispatchTotal, 2),
                'excess_remit' => round($excess, 2),
                'short_remit' => round($short, 2),
                'net_total' => round($fareTotal - $dispatchTotal, 2),
            ];
        }

        return [
            'bus_id' => $bus->id,
            'bus_number' => (string) $bus->bus_number,
            'plate_number' => (string) $bus->plate_number,
            'date' => $date,
            'rows' => $rows,
            'total_passengers' => $totalPassengers,
            'total_dispatch' => round($totalDispatch, 2),
            'total_excess_remit' => round($totalExcess, 2),
            'total_short_remit' => round($totalShort, 2),
            'total_received' => round($totalReceived, 2),
            'total_income' => round($totalReceived - $totalDispatch, 2),
        ];
    }
}
