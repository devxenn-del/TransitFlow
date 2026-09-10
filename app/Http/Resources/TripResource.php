<?php

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trip
 */
class TripResource extends JsonResource
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
            'bus_capacity' => $this->whenLoaded('bus', fn () => $this->bus?->capacity),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id, 'name' => $this->driver->name,
            ] : null),
            'conductor' => $this->whenLoaded('conductor', fn () => $this->conductor ? [
                'id' => $this->conductor->id, 'name' => $this->conductor->name,
            ] : null),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'ticket_count' => $this->whenCounted('tickets'),
            // Live money summary — always present.
            'summary' => [
                'collected' => $this->collectedAmount(),
                'remitted_amount' => $this->remitted_amount !== null ? (float) $this->remitted_amount : null,
                'balance' => $this->remitted_amount !== null
                    ? round($this->collectedAmount() - (float) $this->remitted_amount, 2)
                    : null,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
