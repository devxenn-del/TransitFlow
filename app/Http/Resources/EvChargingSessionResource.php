<?php

namespace App\Http\Resources;

use App\Models\EvChargingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EvChargingSession
 */
class EvChargingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bus_id' => $this->bus_id,
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? [
                'id' => $this->bus->id, 'bus_number' => $this->bus->bus_number,
            ] : null),
            'status' => $this->status,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'battery_start_pct' => $this->battery_start_pct,
            'battery_end_pct' => $this->battery_end_pct,
            'battery_gained_pct' => $this->battery_end_pct !== null
                ? $this->battery_end_pct - $this->battery_start_pct
                : null,
            'duration_minutes' => $this->durationMinutes(),
            'location' => $this->location,
            'notes' => $this->notes,
            'started_by' => $this->started_by_name,
            'ended_by' => $this->ended_by_name,
        ];
    }
}
