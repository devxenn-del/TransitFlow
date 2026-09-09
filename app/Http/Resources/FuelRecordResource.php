<?php

namespace App\Http\Resources;

use App\Models\FuelRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FuelRecord
 */
class FuelRecordResource extends JsonResource
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
            'fuel_type' => $this->fuel_type,
            'liters' => (float) $this->liters,
            'price_per_liter' => (float) $this->price_per_liter,
            'amount_paid' => (float) $this->amount_paid,
            'odometer' => $this->odometer,
            'station' => $this->station,
            'fueled_at' => $this->fueled_at,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by_name,
            'recorded_by_role' => $this->recorded_by_role,
        ];
    }
}
