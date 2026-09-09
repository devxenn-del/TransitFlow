<?php

namespace App\Support\Reports;

use App\Models\Bus;
use App\Models\CashCount;
use App\Models\OpExpense;
use App\Models\Trip;
use Illuminate\Support\Carbon;

/**
 * Daily Operations Report — one consolidated row per bus for an operational
 * day (or a range of them), BITS `App\DailyOperationsReport`
 * (docs/MIGRATION_MAP.md §J).
 *
 * Definitions (unchanged from BITS' "Office Operation Summary"):
 *
 *   gross_income      = passenger fares only (Terminal + Pickup); NOT dispatch
 *   dispatch_total    = barker payouts on that bus's trips
 *   operational_exp   = active op_day_expenses for that bus in the window
 *   remaining_income  = gross_income − dispatch_total − operational_exp
 *   cash_counted      = non-voided bus_day_cash_counts.counted_total for the bus
 *   morning/evening_net = per-shift (gross − dispatch − expenses); the two
 *                         always sum to remaining_income for that bus
 *
 * Keys off `trips.op_date` / `trips.shift` (set by StartTrip) rather than
 * BITS' 4am–4am arithmetic. Company-scoped through the models' global
 * `CompanyScope`. `admin_bus_assignments` scoping is out of scope until that
 * module exists — the report covers every company bus, optionally narrowed
 * to one.
 */
class DailyOperationsReport
{
    /** ₱ denominations, high to low. */
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

    private const SHIFTS = ['Morning', 'Evening'];

    /**
     * @return array{
     *     range: array{from:string, to:string},
     *     rows: list<array<string,mixed>>,
     *     summary: array<string,float|int>
     * }
     */
    public function generate(string|Carbon $fromDate, string|Carbon|null $toDate = null, ?int $busId = null): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to = $toDate === null
            ? $from
            : ($toDate instanceof Carbon ? $toDate->toDateString() : Carbon::parse($toDate)->toDateString());

        $buses = Bus::query()
            ->when($busId !== null, fn ($q) => $q->whereKey($busId))
            ->orderBy('bus_number')
            ->get(['id', 'bus_number', 'plate_number']);

        $fareByBusShift = $this->fareByBusShift($from, $to);
        $dispatchByBusShift = $this->dispatchByBusShift($from, $to);
        $expenseByBusShift = $this->expenseByBusShift($from, $to);
        $cashByBus = $this->cashCountedByBus($from, $to);
        $tripCountByBus = $this->tripCountByBus($from, $to);
        $passengersByBus = $this->passengersByBus($from, $to);

        $rows = [];
        $summary = $this->emptySummary();

        foreach ($buses as $bus) {
            $terminal = 0.0;
            $pickup = 0.0;
            $dispatch = 0.0;
            $expenses = 0.0;
            $shiftNet = ['Morning' => 0.0, 'Evening' => 0.0];

            foreach (self::SHIFTS as $shift) {
                $t = (float) ($fareByBusShift[$bus->id][$shift]['Terminal'] ?? 0);
                $p = (float) ($fareByBusShift[$bus->id][$shift]['Pickup'] ?? 0);
                $d = (float) ($dispatchByBusShift[$bus->id][$shift] ?? 0);
                $e = (float) ($expenseByBusShift[$bus->id][$shift] ?? 0);

                $terminal += $t;
                $pickup += $p;
                $dispatch += $d;
                $expenses += $e;
                $shiftNet[$shift] = round(($t + $p) - $d - $e, 2);
            }

            $gross = round($terminal + $pickup, 2);
            $remaining = round($gross - $dispatch - $expenses, 2);
            $cashCounted = round((float) ($cashByBus[$bus->id] ?? 0), 2);

            $rows[] = [
                'bus_id' => $bus->id,
                'bus_number' => (string) $bus->bus_number,
                'plate_number' => (string) $bus->plate_number,
                'terminal_income' => round($terminal, 2),
                'pickup_income' => round($pickup, 2),
                'dispatch_total' => round($dispatch, 2),
                'operational_expenses' => round($expenses, 2),
                'gross_income' => $gross,
                'remaining_income' => $remaining,
                'cash_counted' => $cashCounted,
                'trips_count' => (int) ($tripCountByBus[$bus->id] ?? 0),
                'passenger_total' => (int) ($passengersByBus[$bus->id] ?? 0),
                'morning_net' => $shiftNet['Morning'],
                'evening_net' => $shiftNet['Evening'],
                'total_daily_net' => round($shiftNet['Morning'] + $shiftNet['Evening'], 2),
            ];

            $summary['terminal'] += $terminal;
            $summary['pickup'] += $pickup;
            $summary['dispatch'] += $dispatch;
            $summary['operational_expenses'] += $expenses;
            $summary['gross_income'] += $gross;
            $summary['cash_counted'] += $cashCounted;
            $summary['total_passenger'] += (int) ($passengersByBus[$bus->id] ?? 0);
            $summary['trips_count'] += (int) ($tripCountByBus[$bus->id] ?? 0);
            $summary['morning_net'] += $shiftNet['Morning'];
            $summary['evening_net'] += $shiftNet['Evening'];
        }

        $summary['expenses'] = round($summary['dispatch'] + $summary['operational_expenses'], 2);
        $summary['remaining_income'] = round($summary['gross_income'] - $summary['dispatch'] - $summary['operational_expenses'], 2);
        $summary['cash_on_hand'] = round($summary['cash_counted'] - $summary['operational_expenses'], 2);
        $summary['total_daily_net'] = round($summary['morning_net'] + $summary['evening_net'], 2);
        foreach (['terminal', 'pickup', 'dispatch', 'operational_expenses', 'gross_income', 'cash_counted', 'morning_net', 'evening_net'] as $key) {
            $summary[$key] = round($summary[$key], 2);
        }

        return [
            'range' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function emptySummary(): array
    {
        return [
            'total_passenger' => 0, 'trips_count' => 0,
            'terminal' => 0.0, 'pickup' => 0.0, 'dispatch' => 0.0,
            'operational_expenses' => 0.0, 'expenses' => 0.0,
            'gross_income' => 0.0, 'remaining_income' => 0.0,
            'cash_counted' => 0.0, 'cash_on_hand' => 0.0,
            'morning_net' => 0.0, 'evening_net' => 0.0, 'total_daily_net' => 0.0,
        ];
    }

    /**
     * @return array<int, array<string, array<string, float>>> [bus][shift][boarding_type] => fare
     */
    private function fareByBusShift(string $from, string $to): array
    {
        $rows = Trip::query()
            ->join('tickets', 'tickets.trip_id', '=', 'trips.id')
            ->whereDate('trips.op_date', '>=', $from)->whereDate('trips.op_date', '<=', $to)
            ->where('trips.status', '!=', 'Cancelled')
            ->whereNull('tickets.refunded_at')
            ->groupBy('trips.bus_id', 'trips.shift', 'tickets.boarding_type')
            ->selectRaw('trips.bus_id, trips.shift, tickets.boarding_type, COALESCE(SUM(tickets.fare), 0) as fare')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->bus_id][(string) $row->shift][(string) $row->boarding_type] = (float) $row->fare;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, float>> [bus][shift] => dispatch total
     */
    private function dispatchByBusShift(string $from, string $to): array
    {
        $rows = Trip::query()
            ->join('dispatches', 'dispatches.trip_id', '=', 'trips.id')
            ->whereDate('trips.op_date', '>=', $from)->whereDate('trips.op_date', '<=', $to)
            ->where('trips.status', '!=', 'Cancelled')
            ->groupBy('trips.bus_id', 'trips.shift')
            ->selectRaw('trips.bus_id, trips.shift, COALESCE(SUM(dispatches.amount), 0) as amount')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->bus_id][(string) $row->shift] = (float) $row->amount;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, float>> [bus][shift] => active expense total
     */
    private function expenseByBusShift(string $from, string $to): array
    {
        $rows = OpExpense::query()
            ->where('status', 'Active')
            ->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to)
            ->groupBy('bus_id', 'shift')
            ->selectRaw('bus_id, shift, COALESCE(SUM(amount), 0) as amount')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->bus_id][(string) $row->shift] = (float) $row->amount;
        }

        return $out;
    }

    /**
     * @return array<int, float> bus => non-voided counted_total
     */
    private function cashCountedByBus(string $from, string $to): array
    {
        return CashCount::query()
            ->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to)
            ->groupBy('bus_id')
            ->selectRaw('bus_id, COALESCE(SUM(counted_total), 0) as counted')
            ->pluck('counted', 'bus_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @return array<int, int> bus => count of Arrived trips
     */
    private function tripCountByBus(string $from, string $to): array
    {
        return Trip::query()
            ->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to)
            ->where('status', 'Arrived')
            ->groupBy('bus_id')
            ->selectRaw('bus_id, COUNT(*) as c')
            ->pluck('c', 'bus_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<int, int> bus => passenger (non-refunded ticket) count
     */
    private function passengersByBus(string $from, string $to): array
    {
        return Trip::query()
            ->join('tickets', 'tickets.trip_id', '=', 'trips.id')
            ->whereDate('trips.op_date', '>=', $from)->whereDate('trips.op_date', '<=', $to)
            ->where('trips.status', '!=', 'Cancelled')
            ->whereNull('tickets.refunded_at')
            ->groupBy('trips.bus_id')
            ->selectRaw('trips.bus_id as bus_id, COUNT(tickets.id) as c')
            ->pluck('c', 'bus_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
