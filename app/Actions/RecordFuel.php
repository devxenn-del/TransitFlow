<?php

namespace App\Actions;

use App\Models\Bus;
use App\Models\FuelRecord;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Records a fuel purchase — BITS `fuel_records` insert
 * (docs/MIGRATION_MAP.md §2.3). `amount_paid = liters * price_per_liter`,
 * rounded to the peso centavo.
 */
class RecordFuel
{
    /**
     * @param  array{bus_id:int, fuel_type:string, liters:float|string, price_per_liter:float|string, odometer?:int|null, station?:string|null, fueled_at?:string|null, notes?:string|null}  $input
     */
    public function handle(User $actor, array $input): FuelRecord
    {
        $bus = Bus::query()->find($input['bus_id']);
        if ($bus === null) {
            throw ValidationException::withMessages(['bus_id' => 'That bus is not in your fleet.']);
        }

        $liters = round((float) $input['liters'], 2);
        $ppl = round((float) $input['price_per_liter'], 2);
        $amount = round($liters * $ppl, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount_paid' => 'Liters and price per liter must be more than zero.']);
        }

        return FuelRecord::query()->create([
            'company_id' => $actor->company_id,
            'bus_id' => $bus->id,
            'fuel_type' => $input['fuel_type'],
            'liters' => $liters,
            'price_per_liter' => $ppl,
            'amount_paid' => $amount,
            'odometer' => $input['odometer'] ?? null,
            'station' => $input['station'] ?? null,
            'fueled_at' => $input['fueled_at'] ?? now(),
            'notes' => $input['notes'] ?? null,
            'recorded_by' => $actor->id,
            'recorded_by_name' => $actor->name,
            'recorded_by_role' => $actor->accessRole?->name,
        ]);
    }
}
