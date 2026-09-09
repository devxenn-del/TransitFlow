<?php

namespace App\Actions;

use App\Models\CashCount;
use App\Models\OpExpense;
use App\Models\RemittanceCashCount;
use App\Support\Denominations;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the `bus_day_cash_counts` rollup for one bus / operating-date
 * / shift from its received remittance counts and active operational
 * expenses (docs/MIGRATION_MAP.md §4.4). Called whenever a remittance count
 * or an expense for that key is created or voided.
 *
 *   counted_total  = SUM(received remittance_cash_counts.counted_total)
 *   remitted_total = SUM(received remittance_cash_counts.expected_amount)
 *   expenses_total = SUM(active op_day_expenses.amount)
 *   net_cash       = counted_total - expenses_total
 */
class RollUpBusDayCashCount
{
    public function handle(int $companyId, int $busId, string $opDate, string $shift): CashCount
    {
        $counts = RemittanceCashCount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('bus_id', $busId)
            ->whereDate('op_date', $opDate)
            ->where('shift', $shift)
            ->received()
            ->get();

        $expenses = OpExpense::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('bus_id', $busId)
            ->whereDate('op_date', $opDate)
            ->where('shift', $shift)
            ->active()
            ->get();

        $denominations = array_fill_keys(array_values(Denominations::FIELDS), 0);
        $countedTotal = 0;
        $remittedTotal = 0;

        foreach ($counts as $count) {
            foreach (Denominations::FIELDS as $field) {
                $denominations[$field] += (int) $count->{$field};
            }
            $countedTotal += (int) $count->counted_total;
            $remittedTotal += (int) $count->expected_amount;
        }

        $expensesTotal = (int) $expenses->sum('amount');

        return DB::transaction(function () use (
            $companyId, $busId, $opDate, $shift, $counts, $expenses,
            $denominations, $countedTotal, $remittedTotal, $expensesTotal
        ): CashCount {
            $rollup = CashCount::query()
                ->where('bus_id', $busId)
                ->whereDate('op_date', $opDate)
                ->where('shift', $shift)
                ->lockForUpdate()
                ->first()
                ?? new CashCount(['bus_id' => $busId, 'op_date' => $opDate, 'shift' => $shift]);

            // A manager-adjusted rollup keeps its manual denominations /
            // counted_total; only the remittance + expense totals refresh.
            $counted = $rollup->isAdjusted() ? (int) $rollup->counted_total : $countedTotal;
            $denomFields = $rollup->isAdjusted() ? [] : $denominations;

            $rollup->fill([
                'company_id' => $companyId,
                ...$denomFields,
                'counted_total' => $counted,
                'remitted_total' => $remittedTotal,
                'expenses_total' => $expensesTotal,
                'net_cash' => $counted - $expensesTotal,
                'trip_count' => $counts->count(),
                'expense_count' => $expenses->count(),
                'variance' => $counted - $remittedTotal,
            ])->save();

            return $rollup;
        });
    }
}
