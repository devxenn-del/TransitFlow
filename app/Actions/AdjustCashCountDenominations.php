<?php

namespace App\Actions;

use App\Models\CashCount;
use App\Models\CashCountHistory;
use App\Models\User;
use App\Support\Denominations;
use App\Support\ManagerVoidPin;
use Illuminate\Support\Facades\DB;

/**
 * A manager corrects the physical denomination tally on a bus/day/shift
 * cash rollup (docs/MIGRATION_MAP.md §4.4). Gated by the manager void-PIN.
 * `counted_total` is recomputed from the new denominations and the row is
 * marked `adjusted`, so `RollUpBusDayCashCount` stops re-deriving it.
 */
class AdjustCashCountDenominations
{
    /**
     * @param  array<string, int>  $denominations
     */
    public function handle(User $manager, CashCount $rollup, array $denominations, string $reason, ?string $pin): CashCount
    {
        ManagerVoidPin::authorize($manager, $pin, 'cashcount:'.$rollup->id);

        $den = Denominations::normalize($denominations);
        $newCounted = Denominations::total($den);
        $delta = $newCounted - (int) $rollup->counted_total;

        return DB::transaction(function () use ($manager, $rollup, $den, $newCounted, $delta, $reason): CashCount {
            $rollup->fill([
                ...$den,
                'counted_total' => $newCounted,
                'net_cash' => $newCounted - (int) $rollup->expenses_total,
                'variance' => $newCounted - (int) $rollup->remitted_total,
                'adjusted_at' => now(),
                'adjusted_by' => $manager->id,
                'adjustment_reason' => $reason,
            ])->save();

            CashCountHistory::query()->create([
                'company_id' => $rollup->company_id,
                'cash_count_id' => $rollup->id,
                'bus_id' => $rollup->bus_id,
                'op_date' => $rollup->op_date,
                'shift' => $rollup->shift,
                'type' => 'Adjustment',
                'ref_code' => 'CC-'.$rollup->id.'-ADJ',
                'description' => 'Denomination adjustment: '.$reason,
                'amount' => $delta,
                ...$den,
                'recorded_by' => $manager->id,
                'recorded_by_name' => $manager->name,
                'authorized_by' => $manager->id,
                'authorized_by_name' => $manager->name,
            ]);

            return $rollup->refresh();
        });
    }
}
