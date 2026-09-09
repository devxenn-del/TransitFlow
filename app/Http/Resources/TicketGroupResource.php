<?php

namespace App\Http\Resources;

use App\Models\TicketGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketGroup
 */
class TicketGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'boarding_type' => $this->boarding_type,
            'payment_method' => $this->payment_method,
            'qr_reference' => $this->qr_reference,
            'line_count' => (int) $this->line_count,
            'passenger_count' => (int) $this->passenger_count,
            'total_fare' => (float) $this->total_fare,
            'issued_at' => $this->issued_at,
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
