<?php

namespace App\Support\Reports;

use App\Models\EvChargingSession;
use App\Models\FuelRecord;
use Illuminate\Support\Carbon;

/**
 * Fuel purchases + EV charging sessions for the Fuel & Energy report, BITS
 * `App\FuelEnergyReport` (docs/MIGRATION_MAP.md §J).
 *
 * The company charges its own EVs, so there are no cost / rate / kWh figures
 * for charging — fuel shows amount / litres / ₱ per litre, EV shows session
 * counts / duration / battery. Company-scoped through the models' global
 * `CompanyScope`.
 */
class FuelEnergyReport
{
    /**
     * @return array{
     *     range: array{from:?string, to:?string},
     *     fuel: array{
     *         rows: list<array<string,mixed>>,
     *         totals: array{fills:int, total_cost:float, total_liters:float, avg_price:?float}
     *     },
     *     charging: array{
     *         rows: list<array<string,mixed>>,
     *         totals: array{sessions:int, active:int, completed:int, total_minutes:int}
     *     }
     * }
     */
    public function generate(string|Carbon|null $fromDate = null, string|Carbon|null $toDate = null, ?int $busId = null, ?string $type = null): array
    {
        $from = $fromDate === null ? null : ($fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString());
        $to = $toDate === null ? null : ($toDate instanceof Carbon ? $toDate->toDateString() : Carbon::parse($toDate)->toDateString());

        $wantFuel = $type === null || $type === '' || in_array($type, ['Diesel', 'Gasoline'], true);
        $wantEv = $type === null || $type === '' || $type === 'EV';

        return [
            'range' => ['from' => $from, 'to' => $to],
            'fuel' => $wantFuel ? $this->fuel($from, $to, $busId, $type) : $this->emptyFuel(),
            'charging' => $wantEv ? $this->charging($from, $to, $busId) : $this->emptyCharging(),
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, totals: array{fills:int, total_cost:float, total_liters:float, avg_price:?float}}
     */
    private function fuel(?string $from, ?string $to, ?int $busId, ?string $type): array
    {
        $records = FuelRecord::query()
            ->with('bus:id,bus_number')
            ->when(in_array($type, ['Diesel', 'Gasoline'], true), fn ($q) => $q->where('fuel_type', $type))
            ->when($busId !== null, fn ($q) => $q->where('bus_id', $busId))
            ->when($from !== null, fn ($q) => $q->whereDate('fueled_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('fueled_at', '<=', $to))
            ->orderByDesc('fueled_at')
            ->get();

        $rows = [];
        $cost = 0.0;
        $liters = 0.0;

        foreach ($records as $record) {
            $cost += (float) $record->amount_paid;
            $liters += (float) $record->liters;

            $rows[] = [
                'id' => $record->id,
                'fueled_at' => $record->fueled_at?->toDateTimeString(),
                'bus_number' => $record->bus?->bus_number,
                'fuel_type' => (string) $record->fuel_type,
                'liters' => round((float) $record->liters, 2),
                'price_per_liter' => round((float) $record->price_per_liter, 2),
                'amount_paid' => round((float) $record->amount_paid, 2),
                'station' => $record->station,
                'recorded_by_name' => $record->recorded_by_name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'fills' => $records->count(),
                'total_cost' => round($cost, 2),
                'total_liters' => round($liters, 2),
                'avg_price' => $liters > 0 ? round($cost / $liters, 2) : null,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, totals: array{sessions:int, active:int, completed:int, total_minutes:int}}
     */
    private function charging(?string $from, ?string $to, ?int $busId): array
    {
        $sessions = EvChargingSession::query()
            ->with('bus:id,bus_number')
            ->when($busId !== null, fn ($q) => $q->where('bus_id', $busId))
            ->when($from !== null, fn ($q) => $q->whereDate('started_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('started_at', '<=', $to))
            ->orderByDesc('started_at')
            ->get();

        $rows = [];
        $active = 0;
        $completed = 0;
        $totalMinutes = 0;

        foreach ($sessions as $session) {
            $minutes = $session->ended_at !== null ? (int) $session->durationMinutes() : 0;
            $totalMinutes += $minutes;
            $session->isCharging() ? $active++ : $completed++;

            $rows[] = [
                'id' => $session->id,
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'bus_number' => $session->bus?->bus_number,
                'status' => (string) $session->status,
                'battery_start_pct' => $session->battery_start_pct,
                'battery_end_pct' => $session->battery_end_pct,
                'battery_gained_pct' => $session->battery_end_pct !== null
                    ? $session->battery_end_pct - $session->battery_start_pct
                    : null,
                'duration_minutes' => $minutes,
                'recorded_by_name' => $session->ended_by_name ?: $session->started_by_name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'sessions' => $sessions->count(),
                'active' => $active,
                'completed' => $completed,
                'total_minutes' => $totalMinutes,
            ],
        ];
    }

    /**
     * @return array{rows: array<int,mixed>, totals: array{fills:int, total_cost:float, total_liters:float, avg_price:null}}
     */
    private function emptyFuel(): array
    {
        return ['rows' => [], 'totals' => ['fills' => 0, 'total_cost' => 0.0, 'total_liters' => 0.0, 'avg_price' => null]];
    }

    /**
     * @return array{rows: array<int,mixed>, totals: array{sessions:int, active:int, completed:int, total_minutes:int}}
     */
    private function emptyCharging(): array
    {
        return ['rows' => [], 'totals' => ['sessions' => 0, 'active' => 0, 'completed' => 0, 'total_minutes' => 0]];
    }
}
