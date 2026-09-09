<?php

namespace App\Http\Resources;

use App\Models\Bus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bus
 */
class BusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'bus_number' => $this->bus_number,
            'plate_number' => $this->plate_number,
            'capacity' => $this->capacity,
            'model' => $this->model,
            'vehicle_type' => $this->vehicle_type,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
