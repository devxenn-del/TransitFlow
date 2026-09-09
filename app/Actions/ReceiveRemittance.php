<?php

namespace App\Actions;

use App\Models\CashCount;
use App\Models\CashCountHistory;
use App\Models\RemittanceCashCount;
use App\Models\Trip;
use App\Models\User;
use App\Support\Denominations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receives a trip's remittance: a cashier counts the physical cash and
 * records the denomination breakdown (docs/MIGRATION_MAP.md §4.3–4.4).
 * Saving stamps `remittance_received_at/by` on the trip and rolls the
 * count into `bus_day_cash_counts`.
 */
class ReceiveRemittance
{
    public function __construct(private readonly RollUpBusDayCashCount $rollUp) {}

    /**
     * @param  array{q1000?:int, q500?:int, q200?:int, q100?:int, q50?:int, q20?:int, q10?:int, q5?:int, q1?:int}  $denominations
     */
    public function handle(User $receiver, Trip $trip, array $denominations): RemittanceCashCount
    {
        if ($trip->status !== 'Arrived') {
            throw ValidationException::withMessages(['trip' => 'Only a completed trip can be received.']);
        }
        if ($trip->remitted_amount === null) {
            throw ValidationException::withMessages(['trip' => 'This trip has no remitted amount yet.']);
        }
        if ($trip->remittance_received_at !== null) {
            throw ValidationException::withMessages(['trip' => 'This remittance has already been received.']);
        }

        $den = Denominations::normalize($denominations);
        $countedTotal = Denominations::total($den);
        if ($countedTotal <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the cash counted for this remittance.']);
        }

        $expected = (int) round((float) $trip->remitted_amount);
        $opDate = ($trip->op_date ?? $trip->started_at ?? now())->toDateString();
        $shift = $trip->shift ?? CashCount::shiftForHour(($trip->started_at ?? now())->hour);

        return DB::transaction(function () use ($receiver, $trip, $den, $countedTotal, $expected, $opDate, $shift): RemittanceCashCount {
            $rcc = RemittanceCashCount::query()->create([
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                'bus_id' => $trip->bus_id,
                'op_date' => $opDate,
                'shift' => $shift,
                ...$den,
                'counted_total' => $countedTotal,
                'expected_amount' => $expected,
                'variance' => $countedTotal - $expected,
                'status' => 'Received',
                'received_by' => $receiver->id,
                'received_by_name' => $receiver->name,
                'received_at' => now(),
            ]);

            $trip->update([
                'remittance_received_at' => now(),
                'remittance_received_by' => $receiver->id,
            ]);

            CashCountHistory::query()->create([
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                'bus_id' => $trip->bus_id,
                'op_date' => $opDate,
                'shift' => $shift,
                'type' => 'RemitReceived',
                'ref_code' => 'RMT-'.$trip->id,
                'description' => "Received trip #{$trip->id} — counted vs expected ".($countedTotal - $expected),
                'amount' => $countedTotal,
                ...$den,
                'recorded_by' => $receiver->id,
                'recorded_by_name' => $receiver->name,
            ]);

            if ($trip->bus_id !== null) {
                $this->rollUp->handle($trip->company_id, $trip->bus_id, $opDate, $shift);
            }

            return $rcc;
        });
    }
}
