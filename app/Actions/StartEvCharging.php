<?php

namespace App\Actions;

use App\Models\Bus;
use App\Models\EvChargingSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Opens an EV charging session — BITS `ev_charging_sessions` start
 * (docs/MIGRATION_MAP.md §2.3). Only one `Charging` session per bus at a
 * time (unique `active_bus_id`).
 */
class StartEvCharging
{
    /**
     * @param  array{bus_id:int, battery_start_pct:int, location?:string|null, notes?:string|null}  $input
     */
    public function handle(User $actor, array $input): EvChargingSession
    {
        $bus = Bus::query()->find($input['bus_id']);
        if ($bus === null) {
            throw ValidationException::withMessages(['bus_id' => 'That bus is not in your fleet.']);
        }

        if (EvChargingSession::query()->where('bus_id', $bus->id)->charging()->exists()) {
            throw ValidationException::withMessages(['bus_id' => 'This bus is already charging.']);
        }

        return EvChargingSession::query()->create([
            'company_id' => $actor->company_id,
            'bus_id' => $bus->id,
            'status' => 'Charging',
            'started_at' => now(),
            'battery_start_pct' => (int) $input['battery_start_pct'],
            'location' => $input['location'] ?? null,
            'notes' => $input['notes'] ?? null,
            'started_by' => $actor->id,
            'started_by_name' => $actor->name,
            'active_bus_id' => $bus->id,
        ]);
    }
}
