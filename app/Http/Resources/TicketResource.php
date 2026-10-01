<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'trip' => $this->whenLoaded('trip', fn () => $this->trip ? [
                'id' => $this->trip->id,
                'reference' => $this->trip->reference,
                'bus_number' => $this->trip->bus_number,
                'conductor' => $this->trip->relationLoaded('conductor') ? $this->trip->conductor?->name : null,
            ] : null),
            'ticket_group_id' => $this->ticket_group_id,
            'route_id' => $this->route_id,
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'origin' => $this->route->origin, 'destination' => $this->route->destination,
            ] : null),
            'passenger_type_id' => $this->passenger_type_id,
            'passenger_type' => $this->whenLoaded('passengerType', fn () => $this->passengerType?->name),
            'article_label' => $this->article_label,
            'boarding_type' => $this->boarding_type,
            'payment_method' => $this->payment_method,
            'qr_reference' => $this->qr_reference,
            'fare' => (float) $this->fare,
            'refunded_at' => $this->refunded_at,
            'issued_at' => $this->issued_at,
        ];
    }
}
