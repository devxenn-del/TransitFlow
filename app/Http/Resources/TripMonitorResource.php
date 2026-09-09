<?php

namespace App\Http\Resources;

use App\Models\Trip;
use App\Support\TripRemittance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trip as seen from the company's admin Trip Monitoring screen — the
 * conductor/bus/driver context plus the full §4.3 remittance breakdown.
 *
 * @mixin Trip
 */
class TripMonitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'is_live' => $this->isLive(),
            'origin' => $this->origin,
            'coverage_origin' => $this->coverage_origin,
            'coverage_destination' => $this->coverage_destination,
            'bus_id' => $this->bus_id,
            'bus_number' => $this->bus_number,
            'conductor' => $this->whenLoaded('conductor', fn () => $this->conductor ? [
                'id' => $this->conductor->id, 'name' => $this->conductor->name,
            ] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id, 'name' => $this->driver->name,
            ] : null),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'force_ended_at' => $this->force_ended_at,
            'force_ended_reason' => $this->force_ended_reason,
            'force_ended_by' => $this->whenLoaded('forceEndedBy', fn () => $this->forceEndedBy?->name),
            'ticket_count' => $this->whenCounted('tickets'),
            'remittance_approved_at' => $this->remittance_approved_at,
            'remittance_approved_by' => $this->whenLoaded('remittanceApprovedBy', fn () => $this->remittanceApprovedBy?->name),
            'remittance_received_at' => $this->remittance_received_at,
            'remittance_locked' => $this->remittanceLockActive(),
            'remittance_locked_by' => $this->remittanceLockActive()
                ? ($this->relationLoaded('remittanceLockedBy') ? $this->remittanceLockedBy?->name : $this->remittanceLockedBy()->value('name'))
                : null,
            'remittance_locked_by_me' => $this->remittanceLockActive()
                && (int) $this->remittance_locked_by === (int) $request->user()?->id,
            'remittance_flagged' => (bool) $this->remittance_flagged,
            'remittance_flag_note' => $this->remittance_flag_note,
            'remittance_note' => $this->remittance_note,
            'remittance' => TripRemittance::for($this->resource)->toArray(),
            'created_at' => $this->created_at,
        ];
    }
}
