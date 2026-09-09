<?php

namespace App\Actions;

use App\Models\CashCountHistory;
use App\Models\RemittanceCashCount;
use App\Models\User;
use App\Support\ManagerVoidPin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a received remittance count — BITS void flow (docs/MIGRATION_MAP.md
 * §4.4). Only a manager may do this, gated by their void-PIN. It rolls the
 * count back out of `bus_day_cash_counts` and reopens the receive step.
 */
class VoidRemittanceReceipt
{
    public function __construct(private readonly RollUpBusDayCashCount $rollUp) {}

    public function handle(User $manager, RemittanceCashCount $count, string $reason, ?string $pin): RemittanceCashCount
    {
        if (! $count->isReceived()) {
            throw ValidationException::withMessages(['remittance' => 'This remittance count is already voided.']);
        }

        $trip = $count->trip;
        if ($trip !== null && $trip->remittance_approved_at !== null) {
            throw ValidationException::withMessages(['remittance' => 'Approved remittances cannot be voided.']);
        }

        ManagerVoidPin::authorize($manager, $pin, 'remittance:'.$count->id);

        return DB::transaction(function () use ($manager, $count, $trip, $reason): RemittanceCashCount {
            $count->update([
                'status' => 'Voided',
                'voided_by' => $manager->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $trip?->update([
                'remittance_received_at' => null,
                'remittance_received_by' => null,
            ]);

            ManagerVoidPin::log($manager, 'remittance:'.$count->id, true, 'Void authorized');

            CashCountHistory::query()->create([
                'company_id' => $count->company_id,
                'trip_id' => $count->trip_id,
                'bus_id' => $count->bus_id,
                'op_date' => $count->op_date,
                'shift' => $count->shift,
                'type' => 'RemitVoid',
                'ref_code' => 'RMT-'.$count->trip_id.'-VOID',
                'description' => 'Voided: '.$reason,
                'amount' => -1 * (int) $count->counted_total,
                ...$count->denominations(),
                'recorded_by' => $manager->id,
                'recorded_by_name' => $manager->name,
                'authorized_by' => $manager->id,
                'authorized_by_name' => $manager->name,
            ]);

            if ($count->bus_id !== null) {
                $this->rollUp->handle($count->company_id, $count->bus_id, $count->op_date->toDateString(), $count->shift);
            }

            return $count->refresh();
        });
    }
}
