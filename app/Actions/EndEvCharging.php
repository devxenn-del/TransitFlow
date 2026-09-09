<?php

namespace App\Actions;

use App\Models\EvChargingSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Closes an EV charging session — BITS `ev_charging_sessions` complete
 * (docs/MIGRATION_MAP.md §2.3). Frees the bus's `active_bus_id` slot.
 */
class EndEvCharging
{
    /**
     * @param  array{battery_end_pct:int, notes?:string|null}  $input
     */
    public function handle(User $actor, EvChargingSession $session, array $input): EvChargingSession
    {
        if (! $session->isCharging()) {
            throw ValidationException::withMessages(['session' => 'This session is already completed.']);
        }

        $end = (int) $input['battery_end_pct'];
        if ($end < $session->battery_start_pct) {
            throw ValidationException::withMessages(['battery_end_pct' => 'End battery % cannot be below the start %.']);
        }

        $session->update([
            'status' => 'Completed',
            'ended_at' => now(),
            'battery_end_pct' => $end,
            'ended_by' => $actor->id,
            'ended_by_name' => $actor->name,
            'notes' => $input['notes'] ?? $session->notes,
            'active_bus_id' => null,
        ]);

        return $session->refresh();
    }
}
