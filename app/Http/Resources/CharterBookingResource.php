<?php

namespace App\Http\Resources;

use App\Models\CharterBooking;
use App\Support\CharterLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A charter booking for the office (company/charters) and — without the
 * money (`withLedger: false`) — for the conductor app (conductor/charters).
 *
 * @mixin CharterBooking
 */
class CharterBookingResource extends JsonResource
{
    private bool $withLedger = true;

    /** The conductor app gets the trip details only, never the charter's money. */
    public function withoutLedger(): self
    {
        $this->withLedger = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'client_name' => $this->client_name,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'contact_email' => $this->contact_email,
            'purpose' => $this->purpose,
            'pickup_point' => $this->pickup_point,
            'destination' => $this->destination,
            'departure_at' => $this->departure_at?->toIso8601String(),
            'return_at' => $this->return_at?->toIso8601String(),
            'passenger_count' => $this->passenger_count,
            'notes' => $this->notes,

            'bus_id' => $this->bus_id,
            'driver_id' => $this->driver_id,
            'conductor_id' => $this->conductor_id,
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? ['id' => $this->bus->id, 'bus_number' => $this->bus->bus_number, 'plate_number' => $this->bus->plate_number] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name, 'driver_code' => $this->driver->driver_code] : null),
            'conductor' => $this->whenLoaded('conductor', fn () => $this->conductor ? ['id' => $this->conductor->id, 'name' => $this->conductor->name] : null),

            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'cancelled_reason' => $this->cancelled_reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),

            // The trip the conductor ran it as: departed = left the pickup point, arrived = End Trip.
            'trip' => $this->whenLoaded('trip', fn () => $this->trip ? [
                'id' => $this->trip->id,
                'reference' => $this->trip->reference,
                'status' => $this->trip->status,
                'started_at' => $this->trip->started_at?->toIso8601String(),
                'departed_at' => $this->trip->marked_on_trip_at?->toIso8601String(),
                'arrived_at' => $this->trip->ended_at?->toIso8601String(),
            ] : null),

            'agreed_price' => $this->when($this->withLedger, fn () => round((float) $this->agreed_price, 2)),
            'settled_by' => $this->when($this->withLedger, fn () => $this->settler?->name),
            'settled_at' => $this->when($this->withLedger, fn () => $this->settled_at?->toIso8601String()),
            'ledger' => $this->when($this->withLedger && $this->relationLoaded('payments'), fn () => CharterLedger::for($this->resource)->toArray()),
            'payments' => $this->when($this->withLedger && $this->relationLoaded('payments'), fn () => $this->payments->sortBy('paid_at')->values()->map(fn ($p) => [
                'id' => $p->id,
                'type' => $p->type,
                'amount' => round((float) $p->amount, 2),
                'method' => $p->method,
                'reference_no' => $p->reference_no,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'notes' => $p->notes,
                'recorded_by' => $p->recorder?->name,
            ])),
            'charges' => $this->when($this->withLedger && $this->relationLoaded('charges'), fn () => $this->charges->sortBy('incurred_at')->values()->map(fn ($c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'category' => $c->category,
                'description' => $c->description,
                'amount' => round((float) $c->amount, 2),
                'incurred_at' => $c->incurred_at?->toIso8601String(),
                'recorded_by' => $c->recorder?->name,
            ])),
        ];
    }
}
