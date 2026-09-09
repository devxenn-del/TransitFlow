<?php

namespace App\Support\Reports;

use App\Models\CashCount;
use Illuminate\Support\Carbon;

/**
 * Per bus / operational-day cash denomination rollups (`bus_day_cash_counts`),
 * BITS `App\CashCountReport` (docs/MIGRATION_MAP.md §J).
 *
 * These rows are built by `App\Actions\RollUpBusDayCashCount` from received
 * remittance counts — there is no hand-entered / voided variant in
 * TransitFlow, so every row in the window feeds the totals. Company-scoped
 * through `CashCount`'s global `CompanyScope`.
 */
class CashCountReport
{
    /** ₱ denominations, high to low. */
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

    /**
     * @return array{
     *     range: array{from:string, to:string},
     *     rows: list<array{
     *         id:int, op_date:string, shift:string, bus_number:?string,
     *         denominations: array<int,int>,
     *         counted_total:float, remitted_total:float, variance:float,
     *         expenses_total:float, net_cash:float, trip_count:int,
     *         adjusted:bool
     *     }>,
     *     denom_totals: array<int,int>,
     *     grand_total: float,
     *     remitted_total: float,
     *     net_cash_total: float
     * }
     */
    public function generate(string|Carbon $fromDate, string|Carbon|null $toDate = null, ?int $busId = null): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to = $toDate === null
            ? $from
            : ($toDate instanceof Carbon ? $toDate->toDateString() : Carbon::parse($toDate)->toDateString());

        $counts = CashCount::query()
            ->with('bus:id,bus_number')
            ->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to)
            ->when($busId !== null, fn ($q) => $q->where('bus_id', $busId))
            ->orderBy('op_date')
            ->orderBy('bus_id')
            ->orderBy('shift')
            ->get();

        $rows = [];
        $denomTotals = array_fill_keys(self::DENOMINATIONS, 0);
        $grandTotal = 0.0;
        $remittedTotal = 0.0;
        $netCashTotal = 0.0;

        foreach ($counts as $count) {
            $denominations = [];
            foreach (self::DENOMINATIONS as $d) {
                $qty = (int) $count->{'q'.$d};
                $denominations[$d] = $qty;
                $denomTotals[$d] += $qty;
            }

            $grandTotal += (float) $count->counted_total;
            $remittedTotal += (float) $count->remitted_total;
            $netCashTotal += (float) $count->net_cash;

            $rows[] = [
                'id' => $count->id,
                'op_date' => $count->op_date->toDateString(),
                'shift' => (string) $count->shift,
                'bus_number' => $count->bus?->bus_number,
                'denominations' => $denominations,
                'counted_total' => round((float) $count->counted_total, 2),
                'remitted_total' => round((float) $count->remitted_total, 2),
                'variance' => round((float) $count->variance, 2),
                'expenses_total' => round((float) $count->expenses_total, 2),
                'net_cash' => round((float) $count->net_cash, 2),
                'trip_count' => (int) $count->trip_count,
                'adjusted' => $count->isAdjusted(),
            ];
        }

        return [
            'range' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'denom_totals' => $denomTotals,
            'grand_total' => round($grandTotal, 2),
            'remitted_total' => round($remittedTotal, 2),
            'net_cash_total' => round($netCashTotal, 2),
        ];
    }
}
