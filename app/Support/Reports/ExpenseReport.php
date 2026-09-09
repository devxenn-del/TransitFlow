<?php

namespace App\Support\Reports;

use App\Models\OpExpense;
use Illuminate\Support\Carbon;

/**
 * Active operational expenses (`op_day_expenses`) grouped by operational day,
 * BITS `App\OpDayExpenseReport` (docs/MIGRATION_MAP.md §J). Voided rows are
 * excluded. Company-scoped through `OpExpense`'s global `CompanyScope`.
 */
class ExpenseReport
{
    /**
     * @return array{
     *     range: array{from:string, to:string},
     *     days: list<array{
     *         op_date:string,
     *         total:float,
     *         items: list<array{
     *             id:int, bus_number:?string, shift:string, category:string,
     *             description:string, amount:float, recorded_by_name:?string,
     *             recorded_at:?string
     *         }>
     *     }>,
     *     grand_total: float
     * }
     */
    public function generate(string|Carbon $fromDate, string|Carbon|null $toDate = null, ?int $busId = null): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to = $toDate === null
            ? $from
            : ($toDate instanceof Carbon ? $toDate->toDateString() : Carbon::parse($toDate)->toDateString());

        $expenses = OpExpense::query()
            ->with('bus:id,bus_number')
            ->where('status', 'Active')
            ->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to)
            ->when($busId !== null, fn ($q) => $q->where('bus_id', $busId))
            ->orderBy('op_date')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $days = [];
        $grandTotal = 0.0;

        foreach ($expenses as $expense) {
            $key = $expense->op_date->toDateString();
            $amount = (float) $expense->amount;
            $grandTotal += $amount;

            $days[$key] ??= ['op_date' => $key, 'total' => 0.0, 'items' => []];
            $days[$key]['total'] += $amount;
            $days[$key]['items'][] = [
                'id' => $expense->id,
                'bus_number' => $expense->bus?->bus_number,
                'shift' => (string) $expense->shift,
                'category' => (string) $expense->category,
                'description' => (string) $expense->description,
                'amount' => round($amount, 2),
                'recorded_by_name' => $expense->recorded_by_name,
                'recorded_at' => $expense->recorded_at?->toDateTimeString(),
            ];
        }

        foreach ($days as &$day) {
            $day['total'] = round($day['total'], 2);
        }
        unset($day);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'days' => array_values($days),
            'grand_total' => round($grandTotal, 2),
        ];
    }
}
