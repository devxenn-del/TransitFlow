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
            'franchise_id' => $this->franchise_id,
            'trip_type' => $this->trip_type,
            // A Special trip's charter booking — trip details only, never its money.
            'charter' => $this->whenLoaded('charterBooking', fn () => $this->charterBooking ? [
                'id' => $this->charterBooking->id,
                'reference' => $this->charterBooking->reference,
                'client_name' => $this->charterBooking->client_name,
                'contact_person' => $this->charterBooking->contact_person,
                'contact_number' => $this->charterBooking->contact_number,
                'purpose' => $this->charterBooking->purpose,
                'pickup_point' => $this->charterBooking->pickup_point,
                'destination' => $this->charterBooking->destination,
                'departure_at' => $this->charterBooking->departure_at?->toIso8601String(),
                'return_at' => $this->charterBooking->return_at?->toIso8601String(),
                'passenger_count' => $this->charterBooking->passenger_count,
            ] : null),
            'at_terminal' => (bool) $this->at_terminal,
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
                // The conductor app's Passengers tile: total, Via Terminal, Via Pick-up.
                'passengers' => $this->passengerCounts(),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
